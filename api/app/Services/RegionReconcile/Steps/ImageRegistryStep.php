<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Contracts\KnativeConstants;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\SandboxInstallService;
use App\Services\Wasm\WasmManifests;
use App\Support\Images\RegistryMove;

/**
 * RunR's image namespace (0.5.1, App\Support\Images\RegistryMove): workloads RunR deployed into
 * the cluster before the upgrade run images under ghcr.io/runrsh/. Their pod templates move to the
 * same images under ghcr.io/brf-tech/ — every container and init container image, and every env
 * value, that starts with ghcr.io/runrsh/ (exact prefix; images of other registries, also the
 * region registry's pull-through path {registry}/ghcr.io/…, stay):
 *
 *  - Knative Services (functions with the wrapper init container or a runtime image, CaaS
 *    projects, model services, hosted MCP servers): a new revision; Knative moves the traffic
 *    to it once it is ready, the running revision serves until then
 *  - Deployments, StatefulSets, DaemonSets (the region agent, queue consumers, the registry and
 *    its node mirror, Loki, binding data services, image pre-pull, …): rolled once
 *  - CronJobs (schedule triggers): from their next run
 *  - Prometheus (prometheus-operator resource of the region's metrics): the operator rolls it once
 *
 * One JSON patch per object, each replaced value guarded by a test operation (a concurrent change
 * makes the patch fail and the next run moves it). Idempotent: a moved object has nothing to patch.
 *
 * Not patched here:
 *  - objects a controller owns (a revision's Deployment, a Job of a CronJob, Prometheus's
 *    StatefulSet) — their owner is patched;
 *  - RunR's own Helm release (the control plane on the same cluster) — the chart maps its images;
 *  - the region Traefik (traefik-image) and the node installers of the sandbox runtimes
 *    (runr-sandbox: wasm-sandbox re-runs the WebAssembly installer; the Kata, Kata devmapper and
 *    gVisor installers keep their pods — rolling them re-runs the install on every node, and
 *    kata-deploy's cleanup restarts the container runtime; they move with the next change of
 *    that installation, and kata-sandbox counts the namespace move alone as no change);
 *  - the region's queue Redis (runr-redis): its streams live in memory only, a restart drops queued
 *    messages — it keeps its pod and moves when it is installed again;
 *  - Jobs and standalone pods (builds, scans, uploads, sandboxes): short-lived, the next ones
 *    start from the moved settings.
 */
final class ImageRegistryStep implements ReconcileStep
{
    /** [apiVersion, kind, path of the pod spec, what the move does to it, served only with a CRD] */
    private const KINDS = [
        ['serving.knative.dev/v1', 'Service', ['spec', 'template', 'spec'], 'a new revision takes the traffic once it is ready', true],
        ['apps/v1', 'Deployment', ['spec', 'template', 'spec'], 'its pods roll once', false],
        ['apps/v1', 'StatefulSet', ['spec', 'template', 'spec'], 'its pods roll once', false],
        ['apps/v1', 'DaemonSet', ['spec', 'template', 'spec'], 'its pods roll once', false],
        ['batch/v1', 'CronJob', ['spec', 'jobTemplate', 'spec', 'template', 'spec'], 'from its next run', false],
        ['monitoring.coreos.com/v1', 'Prometheus', ['spec'], 'the operator rolls it once', true],
    ];

    public function name(): string
    {
        return 'image-registry';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $k8s = $cluster->k8s;
        $lines = [];
        $failed = [];

        foreach (self::KINDS as [$apiVersion, $kind, $specPath, $effect, $optional]) {
            if ($optional && ! $this->serves($k8s, $apiVersion, $kind)) {
                continue;
            }

            $list = $k8s->listClusterResources($apiVersion, $kind);
            if ($list === null) {
                throw new \RuntimeException("cannot list {$kind}s ({$apiVersion})");
            }

            foreach ($list['items'] ?? [] as $object) {
                $ops = self::patch($object, $specPath);
                if ($ops === []) {
                    continue;
                }

                $ns = (string) ($object['metadata']['namespace'] ?? '');
                $name = (string) ($object['metadata']['name'] ?? '');
                $what = "{$kind} {$ns}/{$name}";
                $images = self::movedImages($ops);

                $skip = $this->leftAlone($object, $kind, $cluster);
                if ($skip === false) {
                    continue;
                }
                if (is_string($skip)) {
                    $lines[] = "kept: {$what} runs ".implode(', ', $images)." — {$skip}";

                    continue;
                }

                $summary = "{$what}: ".implode(', ', array_map(fn ($i) => $i.' → '.RegistryMove::ref($i), $images))." — {$effect}";
                if ($dryRun) {
                    $lines[] = "would move {$summary}";

                    continue;
                }
                if (! $k8s->jsonPatch($apiVersion, $kind, $name, $ns, $ops)) {
                    $failed[] = $what;

                    continue;
                }
                $lines[] = "moved {$summary}";
            }
        }

        if ($failed) {
            throw new \RuntimeException(count($failed).' workload(s) not moved to '.RegistryMove::TO.' (patch refused, see the API log; the next run tries again): '
                .implode(', ', $failed).($lines ? ' — done: '.implode('; ', $lines) : ''));
        }

        return $lines;
    }

    /**
     * JSON patch operations that move the pod spec at $specPath: test + replace for every image
     * and env value under RegistryMove::FROM. Empty when there is nothing to move.
     *
     * @param  list<string>  $specPath
     * @return list<array{op: string, path: string, value: string}>
     */
    public static function patch(array $object, array $specPath): array
    {
        $spec = $object;
        foreach ($specPath as $key) {
            $spec = is_array($spec) ? ($spec[$key] ?? null) : null;
        }
        if (! is_array($spec)) {
            return [];
        }

        $base = '/'.implode('/', $specPath);
        $ops = [];
        foreach (['initContainers', 'containers'] as $list) {
            foreach ((array) ($spec[$list] ?? []) as $i => $container) {
                $path = "{$base}/{$list}/{$i}";
                $image = $container['image'] ?? null;
                if (is_string($image) && RegistryMove::ref($image) !== $image) {
                    array_push($ops, ['op' => 'test', 'path' => "{$path}/image", 'value' => $image], ['op' => 'replace', 'path' => "{$path}/image", 'value' => RegistryMove::ref($image)]);
                }
                foreach ((array) ($container['env'] ?? []) as $j => $env) {
                    $value = $env['value'] ?? null;
                    if (is_string($value) && RegistryMove::ref($value) !== $value) {
                        array_push($ops, ['op' => 'test', 'path' => "{$path}/env/{$j}/value", 'value' => $value], ['op' => 'replace', 'path' => "{$path}/env/{$j}/value", 'value' => RegistryMove::ref($value)]);
                    }
                }
            }
        }

        return $ops;
    }

    /**
     * Why an object is not patched here: false = another step or its owner takes care of it
     * (nothing to say), a string = it keeps its pods (said in the output), null = patch it.
     */
    private function leftAlone(array $object, string $kind, ClusterGroup $cluster): string|false|null
    {
        $meta = $object['metadata'] ?? [];
        foreach ((array) ($meta['ownerReferences'] ?? []) as $owner) {
            if (! empty($owner['controller'])) {
                return false;
            }
        }

        // RunR's own release: `helm upgrade` renders its images (chart helper runr.image)
        if (str_starts_with((string) ($meta['labels']['helm.sh/chart'] ?? ''), 'runr-')) {
            return false;
        }

        $ns = (string) ($meta['namespace'] ?? '');
        $name = (string) ($meta['name'] ?? '');

        if ($kind === 'Deployment' && $name === KnativeConstants::TRAEFIK_RELEASE && in_array($ns, $cluster->servingNamespaces(), true)) {
            return false; // traefik-image
        }

        if ($kind === 'DaemonSet' && in_array($ns, [WasmManifests::NAMESPACE, SandboxInstallService::SANDBOX_NAMESPACE], true)) {
            return $name === WasmManifests::INSTALLER_DS
                ? false // wasm-sandbox
                : 'node installer of a sandbox runtime: rolling it re-runs the install on every node (Kata: kata-deploy\'s cleanup '
                    .'restarts the container runtime); it moves to '.RegistryMove::TO.' with the next change of that installation';
        }

        if ($kind === 'Deployment' && ($meta['labels']['app.kubernetes.io/name'] ?? null) === 'runr-redis') {
            return 'the region\'s queue Redis keeps its streams in memory only (a restart drops queued messages); it moves to '
                .RegistryMove::TO.' when it is installed again';
        }

        return null;
    }

    /** @return list<string> the images (and image-like env values) a patch moves, unique */
    private static function movedImages(array $ops): array
    {
        $images = [];
        foreach ($ops as $op) {
            if ($op['op'] === 'test') {
                $images[$op['value']] = true;
            }
        }

        return array_keys($images);
    }

    /** Does the cluster serve $kind of $apiVersion (its CRD installed)? */
    private function serves(KubernetesClient $k8s, string $apiVersion, string $kind): bool
    {
        foreach ((array) ($k8s->getApiResources($apiVersion)['resources'] ?? []) as $resource) {
            if (($resource['kind'] ?? null) === $kind && ! str_contains((string) ($resource['name'] ?? ''), '/')) {
                return true;
            }
        }

        return false;
    }
}
