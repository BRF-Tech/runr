<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\Observability\RegionTracingConfig;
use App\Services\Helpers\KubeQuantity;
use App\Services\Helpers\ManagedTracing;
use App\Services\Observability\TracingInstallService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Support\Images\ImageReference;

/**
 * Region tracing backend (0.5.2): installs or updates the OpenTelemetry Collector and Grafana Tempo
 * (ManagedTracing) where tracing is enabled, and nowhere else:
 *
 *  - a region with its own tracing setting: its mode — managed (collector + Tempo), reuse /
 *    external (the collector only), off (nothing);
 *  - a region without one (Knative Serving installed before RunR had tracing, or without it): the
 *    platform's, observability.tracing.defaultMode while observability.tracing.installWithServing
 *    is on — what a region installed now gets with its Serving.
 *
 * A missing backend is installed, one whose chart version, image, CPU / memory requests or init
 * containers differ from this release's (the pinned charts, their images from the reqs mirror,
 * ManagedTracing's requests, Tempo's fix-permissions) is updated: TracingInstallService::install(),
 * as `php artisan cluster:install-tracing` does — Helm upgrade --install of the releases with their
 * values rendered anew, the span-metrics PodMonitor and Knative's tracing configuration. The
 * collector and Tempo pods roll once; Tempo keeps its traces on its volume. A Tempo pod that is
 * not ready with an earlier template (crash-looping) would hold up its StatefulSet's update: it
 * counts as a change too, and the install replaces it. The region's tracing status is
 * "installing" until the pods run (observability:run tracing-status).
 *
 * The backend is cluster-wide (namespace observability): the first region of the cluster with
 * tracing enabled decides it, as its own install would.
 */
final class TracingBackendStep implements ReconcileStep
{
    public function name(): string
    {
        return 'tracing';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        foreach ($cluster->targets as $target) {
            $config = RegionTracingConfig::forRegion($target->region);
            $why = self::enabledBy($config);
            if ($why === null) {
                continue;
            }

            $line = $this->reconcile($cluster, $target, $config, $why, $dryRun);

            return $line === null ? [] : [$line];
        }

        return [];
    }

    /**
     * Why tracing is enabled for the region, or null when it is not: never anything installed then.
     */
    public static function enabledBy(RegionTracingConfig $config): ?string
    {
        if ($config->exists) {
            return $config->hasCollector() ? "the region's setting" : null;
        }

        if (! config('observability.tracing.install_with_serving', true) || ! $config->hasCollector()) {
            return null;
        }

        return 'the platform default: observability.tracing.defaultMode with installWithServing';
    }

    private function reconcile(ClusterGroup $cluster, ReconcileTarget $target, RegionTracingConfig $config, string $why, bool $dryRun): ?string
    {
        $components = self::components($config);
        $missing = [];
        $changed = [];

        foreach ($components as $label => $component) {
            $wanted = $component['image'].' (chart '.$component['chart'].($component['version'] !== '' ? "-{$component['version']}" : '').')';
            $live = $cluster->k8s->getResourceStatus('apps/v1', $component['kind'], $component['release'], ManagedTracing::NAMESPACE);
            if (! $live) {
                $missing[$label] = $wanted;

                continue;
            }

            $diff = self::diff($live, $component);
            // a crash-looping Tempo pod keeps its StatefulSet from rolling: the install replaces it
            if ($component['release'] === ManagedTracing::TEMPO_RELEASE) {
                foreach (app(TracingInstallService::class)->stuckTempoPods($cluster->k8s, $live) as $pod) {
                    $diff[] = "pod {$pod} not ready with an earlier template (holds up the update: replaced)";
                }
            }
            if ($diff !== []) {
                $changed[$label] = implode(', ', $diff);
            }
        }

        if ($missing === [] && $changed === []) {
            return null;
        }

        $region = $target->region;
        $install = count($missing) === count($components);
        $changes = [];
        foreach ($missing as $label => $wanted) {
            $changes[] = $install ? "{$label} {$wanted}" : "{$label} not installed → {$wanted}";
        }
        foreach ($changed as $label => $diff) {
            $changes[] = "{$label} {$diff}";
        }

        $what = "the tracing backend of region {$region->name} (mode {$config->mode}, {$why}): ".implode('; ', $changes);
        $after = $install
            ? ' in namespace '.ManagedTracing::NAMESPACE.', Knative sends its spans to the collector'
            : ' — the '.($config->mode === 'managed' ? 'collector and Tempo pods roll' : 'collector pod rolls').' once'
                .($config->mode === 'managed' ? ', Tempo keeps its traces' : '');

        if ($dryRun) {
            return ($install ? 'would install ' : 'would update ').$what.$after;
        }

        $target->run(fn () => app(TracingInstallService::class)->install($region, null, $cluster->k8s));

        return ($install ? 'installed ' : 'updated ').$what.$after
            .'; tracing status "installing" until '.($config->mode === 'managed' ? 'they run' : 'it runs');
    }

    /**
     * The workloads of the backend in the region's mode, with this release's chart version, image,
     * requests of the chart's container and init containers (name => what it does).
     *
     * @return array<string, array{kind: string, release: string, chart: string, version: string, image: string, requests: array<string, string>, init: array<string, string>}>
     */
    private static function components(RegionTracingConfig $config): array
    {
        $components = [
            'OpenTelemetry Collector' => [
                'kind' => 'Deployment',
                'release' => ManagedTracing::COLLECTOR_RELEASE,
                'chart' => ManagedTracing::COLLECTOR_CHART,
                'version' => (string) config('observability.tracing.collector_chart_version', ''),
                'image' => ManagedTracing::collectorImage(),
                'requests' => ManagedTracing::COLLECTOR_REQUESTS,
                'init' => [],
            ],
        ];

        if ($config->mode === 'managed') {
            $components['Grafana Tempo'] = [
                'kind' => 'StatefulSet',
                'release' => ManagedTracing::TEMPO_RELEASE,
                'chart' => ManagedTracing::TEMPO_CHART,
                'version' => (string) config('observability.tracing.tempo_chart_version', ''),
                'image' => ManagedTracing::tempoImage(),
                'requests' => ManagedTracing::TEMPO_REQUESTS,
                'init' => [ManagedTracing::TEMPO_FIX_PERMISSIONS => 'chown of '.ManagedTracing::TEMPO_DATA_PATH],
            ];
        }

        return $components;
    }

    /**
     * What a running workload has of another chart version, image or requests (its helm.sh/chart
     * label, the image and requests of the chart's container), and the init containers it lacks.
     *
     * @param  array{kind: string, release: string, chart: string, version: string, image: string, requests: array<string, string>, init: array<string, string>}  $component
     * @return list<string>
     */
    private static function diff(array $live, array $component): array
    {
        $label = (string) ($live['metadata']['labels']['helm.sh/chart'] ?? '');
        $prefix = $component['chart'].'-';
        $version = str_starts_with($label, $prefix) ? substr($label, strlen($prefix)) : '';

        $containers = $live['spec']['template']['spec']['containers'] ?? [];
        $container = collect($containers)->firstWhere('name', $component['chart']) ?? ($containers[0] ?? []);
        $image = (string) ($container['image'] ?? '');

        $diff = [];
        if ($component['version'] !== '' && $version !== $component['version']) {
            $diff[] = "chart {$component['chart']} ".($version !== '' ? $version : '(unknown)')." → {$component['version']}";
        }
        if ($image === '' || ! ImageReference::same($image, $component['image'])) {
            $diff[] = 'image '.($image !== '' ? $image : '(unknown)')." → {$component['image']}";
        }

        $requests = (array) ($container['resources']['requests'] ?? []);
        foreach ($component['requests'] as $resource => $wanted) {
            $current = $requests[$resource] ?? null;
            $same = $current !== null && ($resource === 'cpu'
                ? KubeQuantity::millicores($current) === KubeQuantity::millicores($wanted)
                : KubeQuantity::bytes($current) === KubeQuantity::bytes($wanted));
            if (! $same) {
                $diff[] = "{$resource} request ".($current !== null ? (string) $current : '(none)')." → {$wanted}";
            }
        }

        $init = array_column($live['spec']['template']['spec']['initContainers'] ?? [], 'name');
        foreach ($component['init'] as $name => $what) {
            if (! in_array($name, $init, true)) {
                $diff[] = "init container {$name} ({$what}) added";
            }
        }

        return $diff;
    }
}
