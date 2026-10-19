<?php

namespace App\Services\Observability;

use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Services\Concerns\KnativeObservabilityHATrait;
use App\Services\HelmService;
use App\Services\Helpers\ManagedTracing;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Installs, reconfigures and removes a region's tracing backend (ManagedTracing): the
 * OpenTelemetry Collector gateway in every mode but "off", Grafana Tempo in mode "managed",
 * the span-metrics PodMonitor and Knative's activator / queue-proxy tracing configuration.
 * Helm runs without --wait; refreshStatus() moves installing → ready once the pods run. A Tempo
 * pod that would hold up the update of its StatefulSet (stuckTempoPods()) is replaced.
 */
class TracingInstallService
{
    use KnativeObservabilityHATrait;

    /** @var (callable(string): void)|null */
    private $log = null;

    /**
     * Hook of KnativeInstallService::installServing(): installs the region's tracing backend
     * with Serving (default mode, or the configuration already stored). Never fails the Serving
     * install — an error is logged and stored on the region's tracing status.
     */
    public function installWithServing(KnativeRegion $region, KubernetesClient $k8s, HelmService $helm, ?callable $log = null): void
    {
        if (! config('observability.tracing.install_with_serving', true)) {
            return;
        }

        try {
            $this->install($region, $log, $k8s, $helm);
        } catch (Throwable $e) {
            if ($log) {
                $log('Tracing backend install failed: '.$e->getMessage());
            }
            Log::warning('Tracing backend install failed', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Install or reconfigure the region's tracing backend for its stored mode.
     *
     * @throws Throwable the install failed (status error is stored)
     */
    public function install(KnativeRegion $region, ?callable $log = null, ?KubernetesClient $k8s = null, ?HelmService $helm = null): RegionTracingConfig
    {
        $this->log = $log;
        $config = RegionTracingConfig::forRegion($region);
        $config->tenant_id ??= $region->owner_tenant_id ?? null;

        if (! $region->kubeconfig) {
            throw new RuntimeException(__('observability.tracing.no_cluster'));
        }

        $k8s ??= new KubernetesClient($region->kubeconfig);
        $helm ??= app(HelmService::class);
        $servingNs = $region->knative_serving_namespace ?: 'knative-serving';

        if ($config->mode === 'off') {
            $this->say('Tracing is off for this region: Knative tracing disabled, nothing installed.');
            $this->applyKnativeTracing($k8s, $servingNs, false, $config->knative_sample_rate);
            $config->fill(['status' => null, 'status_message' => null])->save();

            return $config;
        }

        $config->fill(['status' => RegionTracingConfig::STATUS_INSTALLING, 'status_message' => null])->save();

        try {
            $this->ensureNamespace($k8s);
            $k8s->applyClusterResource($this->observabilityPriorityClass());

            if ($config->mode === 'managed') {
                $this->say('Installing Grafana Tempo (background)...');
                $helm->repoAdd(ManagedTracing::TEMPO_REPO, ManagedTracing::TEMPO_REPO_URL);
                $helm->repoUpdate();
                $result = $helm->install(
                    release: ManagedTracing::TEMPO_RELEASE,
                    chart: (string) config('observability.tracing.tempo_chart', ManagedTracing::TEMPO_REPO.'/tempo'),
                    namespace: ManagedTracing::NAMESPACE,
                    setValues: [],
                    kubeconfigYaml: $region->kubeconfig,
                    version: (string) config('observability.tracing.tempo_chart_version', '') ?: null,
                    valuesArray: ManagedTracing::tempoValues($region->registry_storage_config['storageClass'] ?? '', $config->retention_hours),
                    wait: false,
                );
                if (! $result->success) {
                    throw new RuntimeException('Tempo: '.$result->error);
                }
                $this->replaceStuckTempoPods($k8s);
                $config->tempo_url = ManagedTracing::managedTempoUrl();
            } else {
                // A previous managed Tempo is left alone (its traces stay readable until removed)
                $this->say($config->mode === 'reuse'
                    ? "Reusing Tempo at {$config->tempo_url} (collector exports to {$config->tempo_otlp_endpoint})..."
                    : "Exporting spans to {$config->export_endpoint} (no Tempo in the region)...");
            }

            if ($config->mode === 'external') {
                $k8s->applyResource(ManagedTracing::exportSecret($config->export_headers ?? []), ManagedTracing::NAMESPACE);
            }

            $this->say('Installing the OpenTelemetry Collector gateway (background)...');
            $helm->repoAdd(ManagedTracing::COLLECTOR_REPO, ManagedTracing::COLLECTOR_REPO_URL);
            $helm->repoUpdate();
            $result = $helm->install(
                release: ManagedTracing::COLLECTOR_RELEASE,
                chart: (string) config('observability.tracing.collector_chart', 'open-telemetry/opentelemetry-collector'),
                namespace: ManagedTracing::NAMESPACE,
                setValues: [],
                kubeconfigYaml: $region->kubeconfig,
                version: (string) config('observability.tracing.collector_chart_version', '') ?: null,
                valuesArray: ManagedTracing::collectorValues($config, $region->name),
                wait: false,
            );
            if (! $result->success) {
                throw new RuntimeException('OpenTelemetry Collector: '.$result->error);
            }

            $this->applySpanMetricsScrape($k8s);
            $this->applyKnativeTracing($k8s, $servingNs, true, $config->knative_sample_rate);

            $config->fill([
                'status' => RegionTracingConfig::STATUS_INSTALLING,
                'status_message' => null,
                'installed_at' => now(),
            ])->save();
            $this->say('Tracing backend installed; ready once the collector'.($config->mode === 'managed' ? ' and Tempo are' : ' is').' running.');
        } catch (Throwable $e) {
            $config->fill(['status' => RegionTracingConfig::STATUS_ERROR, 'status_message' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e;
        }

        return $config;
    }

    /**
     * Remove the collector (and RunR's Tempo, with its traces) and switch Knative tracing off.
     * The region's mode becomes "off": a removed backend stays removed — region:reconcile
     * (TracingBackendStep) installs a backend wherever the mode has a collector. Setting a
     * mode again (PUT …/tracing) brings it back.
     */
    public function uninstall(KnativeRegion $region, ?KubernetesClient $k8s = null, ?HelmService $helm = null): RegionTracingConfig
    {
        $config = RegionTracingConfig::forRegion($region);
        $k8s ??= new KubernetesClient($region->kubeconfig);
        $helm ??= app(HelmService::class);

        $this->applyKnativeTracing($k8s, $region->knative_serving_namespace ?: 'knative-serving', false, $config->knative_sample_rate);
        $helm->uninstall(ManagedTracing::COLLECTOR_RELEASE, ManagedTracing::NAMESPACE, $region->kubeconfig);
        if ($config->mode === 'managed') {
            $helm->uninstall(ManagedTracing::TEMPO_RELEASE, ManagedTracing::NAMESPACE, $region->kubeconfig);
        }
        $k8s->deleteResource('v1', 'Secret', ManagedTracing::EXPORT_SECRET, ManagedTracing::NAMESPACE);
        $k8s->deleteResource('monitoring.coreos.com/v1', 'PodMonitor', 'otel-collector-spanmetrics', ManagedTracing::NAMESPACE);

        $config->tenant_id ??= $region->owner_tenant_id ?? null;
        $config->fill(['mode' => 'off', 'status' => null, 'status_message' => null, 'installed_at' => null])->save();

        return $config;
    }

    /**
     * installing → ready when the collector (and in managed mode Tempo) pods run; error stays.
     */
    public function refreshStatus(KnativeRegion $region, ?KubernetesClient $k8s = null): RegionTracingConfig
    {
        $config = RegionTracingConfig::forRegion($region);
        if ($config->status !== RegionTracingConfig::STATUS_INSTALLING || ! $region->kubeconfig) {
            return $config;
        }

        $k8s ??= new KubernetesClient($region->kubeconfig);

        $required = ['opentelemetry-collector' => ManagedTracing::COLLECTOR_RELEASE];
        if ($config->mode === 'managed') {
            $required['tempo'] = ManagedTracing::TEMPO_RELEASE;
        }

        foreach ($required as $name => $instance) {
            if (! $this->podRunning($k8s, $name, $instance)) {
                return $config;
            }
        }

        $config->fill(['status' => RegionTracingConfig::STATUS_READY, 'status_message' => null])->save();

        return $config;
    }

    /**
     * Tempo pods that hold up their StatefulSet's rolling update: not ready and of a revision
     * before the current template's. With the chart's pod management (OrderedReady) the
     * controller waits for such a pod to become ready before it replaces it, and one that
     * crash-loops never does — e.g. on a volume Tempo cannot write, which the new template's
     * fix-permissions init container fixes. Empty while the controller has not seen the current
     * template yet ($statefulSet: the StatefulSet as read already).
     *
     * @return list<string> pod names
     */
    public function stuckTempoPods(KubernetesClient $k8s, ?array $statefulSet = null): array
    {
        $set = $statefulSet ?? $k8s->getResourceStatus('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, ManagedTracing::NAMESPACE);
        $revision = (string) ($set['status']['updateRevision'] ?? '');
        if (! $set || $revision === '' || ! self::observed($set)) {
            return [];
        }

        $pods = $k8s->listResources('v1', 'Pod', ManagedTracing::NAMESPACE, [
            'app.kubernetes.io/name' => ManagedTracing::TEMPO_CHART,
            'app.kubernetes.io/instance' => ManagedTracing::TEMPO_RELEASE,
        ]);

        $stuck = [];
        foreach ($pods['items'] ?? [] as $pod) {
            $podRevision = (string) ($pod['metadata']['labels']['controller-revision-hash'] ?? $revision);
            if ($podRevision !== $revision && ! self::podReady($pod) && empty($pod['metadata']['deletionTimestamp'])) {
                $stuck[] = (string) $pod['metadata']['name'];
            }
        }

        return $stuck;
    }

    /**
     * After a Helm upgrade of Tempo: deletes the pods that would hold up its rolling update
     * (stuckTempoPods()) once the StatefulSet controller has seen the new template (it takes
     * moments; 4.5 s at most are waited for), and the controller starts them again from it.
     * Tempo keeps its traces on its volume.
     */
    private function replaceStuckTempoPods(KubernetesClient $k8s): void
    {
        $set = null;
        for ($i = 0; $i < 10; $i++) {
            if ($i > 0) {
                Sleep::for(500)->milliseconds();
            }
            $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, ManagedTracing::NAMESPACE);
            if (! $set || self::observed($set)) {
                break;
            }
        }
        if (! $set) {
            return;
        }

        foreach ($this->stuckTempoPods($k8s, $set) as $pod) {
            $k8s->deleteResource('v1', 'Pod', $pod, ManagedTracing::NAMESPACE);
            $this->say("Tempo pod {$pod} was not ready with the previous settings: deleted, its StatefulSet starts it with the new ones.");
        }
    }

    /** Whether the StatefulSet controller has seen the object's current spec. */
    private static function observed(array $set): bool
    {
        return (int) ($set['status']['observedGeneration'] ?? 0) >= (int) ($set['metadata']['generation'] ?? 0);
    }

    /** The pod's Ready condition is True. */
    private static function podReady(array $pod): bool
    {
        foreach ($pod['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                return ($condition['status'] ?? '') === 'True';
            }
        }

        return false;
    }

    private function podRunning(KubernetesClient $k8s, string $name, string $instance): bool
    {
        $pods = $k8s->listResources('v1', 'Pod', ManagedTracing::NAMESPACE, [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/instance' => $instance,
        ]);

        foreach ($pods['items'] ?? [] as $pod) {
            if (($pod['status']['phase'] ?? '') !== 'Running') {
                continue;
            }
            $ready = collect($pod['status']['containerStatuses'] ?? [])->every(fn ($c) => (bool) ($c['ready'] ?? false));
            if ($ready) {
                return true;
            }
        }

        return false;
    }

    /**
     * Activator / queue-proxy spans to the collector (or off): through the KnativeServing CR, so
     * the operator keeps it, and directly on config-observability so it applies at once.
     */
    public function applyKnativeTracing(KubernetesClient $k8s, string $servingNs, bool $enabled, float $rate): void
    {
        $tracing = ManagedTracing::knativeTracingConfig($enabled, $rate);

        $patched = $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs, [
            'spec' => ['config' => $tracing],
        ]);
        $this->say($patched
            ? 'Knative tracing '.($enabled ? 'sent to the collector (sampling '.$tracing['observability']['tracing-sampling-rate'].').' : 'disabled.')
            : 'KnativeServing CR not found: Knative tracing configuration skipped.');

        if ($k8s->getResourceStatus('v1', 'ConfigMap', 'config-observability', $servingNs) !== null) {
            $k8s->mergePatch('v1', 'ConfigMap', 'config-observability', $servingNs, ['data' => $tracing['observability']]);
        }
        if ($k8s->getResourceStatus('v1', 'ConfigMap', 'config-tracing', $servingNs) !== null) {
            $k8s->mergePatch('v1', 'ConfigMap', 'config-tracing', $servingNs, ['data' => $tracing['tracing']]);
        }
    }

    /** PodMonitor of the span metrics when the cluster runs the Prometheus Operator. */
    private function applySpanMetricsScrape(KubernetesClient $k8s): void
    {
        if (! $k8s->getClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', 'podmonitors.monitoring.coreos.com')) {
            $this->say('No Prometheus Operator CRDs: span metrics are not scraped (error-rate / latency / cold-start alerts need them).');

            return;
        }

        $k8s->applyResource(ManagedTracing::spanMetricsPodMonitor(), ManagedTracing::NAMESPACE);
        $this->say('Span metrics PodMonitor applied (label app.kubernetes.io/part-of=runr).');
    }

    private function ensureNamespace(KubernetesClient $k8s): void
    {
        if ($k8s->getClusterResource('v1', 'Namespace', ManagedTracing::NAMESPACE)) {
            return;
        }

        $k8s->createClusterResource([
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => ['name' => ManagedTracing::NAMESPACE, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
        ]);
    }

    private function say(string $message): void
    {
        if ($this->log) {
            ($this->log)($message);
        }
    }
}
