<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Concerns\ZotManifestTrait;
use App\Services\Concerns\ZotPrepullTrait;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\Manifests\ZotManifests;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;

class ZotRegistryService
{
    use KnativeClusterTrait, ZotManifestTrait, ZotPrepullTrait;

    private const ZOT_HELM_REPO_NAME = 'zot';

    private const ZOT_HELM_REPO_URL = 'https://zotregistry.dev/helm-charts';

    private const ZOT_HELM_CHART = 'zot/zot';

    // Pinned: values (persistence bool + pvc.*) follow this chart version (zot v2.1.21)
    private const ZOT_CHART_VERSION = '0.1.125';

    public const ZOT_RELEASE_NAME = 'runr-zot';

    public const ZOT_DEFAULT_NS = 'runr-registry';

    // The chart's ConfigMap ({release}-config, mountConfig + configFiles) and its key
    public const ZOT_CONFIG_MAP = 'runr-zot-config';

    public const ZOT_CONFIG_KEY = 'config.json';

    // Pod template annotation that restarts Zot after RunR changed its config.json
    public const CONFIG_HASH_ANNOTATION = 'runr.sh/zot-config-sha256';

    /**
     * Install Zot registry in mirror (pull-through cache) mode.
     *
     * @return array{address: string}
     */
    public function install(string $kubeconfig, array $config): array
    {
        $ns = $config['namespace'] ?? self::ZOT_DEFAULT_NS;
        $storageType = $config['storage_type'];
        $workersOnly = ! empty($config['workers_only']);
        $k8s = new KubernetesClient($kubeconfig);

        $this->ensureNamespace($k8s, $ns);

        $values = match ($storageType) {
            'pvc' => $this->buildZotPvcValues(
                $config['storage_config']['storageClass'] ?? '',
                $config['storage_config']['size'],
                $workersOnly,
            ),
            's3' => $this->buildZotS3Values($config['storage_config'], $workersOnly),
        };

        // Existing StatefulSet: volumeClaimTemplates are immutable → keep them as created
        if ($storageType === 'pvc') {
            $existing = $k8s->getResourceStatus('apps/v1', 'StatefulSet', self::ZOT_RELEASE_NAME, $ns);
            $values = ZotManifests::preserveExistingPvc($values, $existing);
            if ($existing && ($values['pvc']['storage'] ?? null) !== $config['storage_config']['size']) {
                Log::warning('Zot PVC already exists — keeping its size/class (volumeClaimTemplates are immutable)', [
                    'namespace' => $ns, 'size' => $values['pvc']['storage'] ?? null,
                ]);
            }
        }

        $helm = new HelmService;
        $helm->repoAdd(self::ZOT_HELM_REPO_NAME, self::ZOT_HELM_REPO_URL);
        $helm->repoUpdate();

        $result = $helm->install(
            release: self::ZOT_RELEASE_NAME,
            chart: self::ZOT_HELM_CHART,
            namespace: $ns,
            setValues: [],
            kubeconfigYaml: $kubeconfig,
            version: self::ZOT_CHART_VERSION,
            valuesArray: $values,
        );

        if (! $result->success) {
            throw new \RuntimeException('Zot Helm install failed: '.$result->error);
        }

        // Zot chart ignores securityContext/initContainers values — patch StatefulSet directly
        if ($storageType === 'pvc') {
            $this->patchZotPermissions($k8s, $ns);
        }

        $this->waitForZotReady($k8s, $ns);

        // Use ClusterIP instead of DNS — kubelet image pull can't resolve svc.cluster.local
        $svc = $k8s->getResourceStatus('v1', 'Service', self::ZOT_RELEASE_NAME, $ns);
        $clusterIp = $svc['spec']['clusterIP'] ?? null;
        $address = $clusterIp
            ? "{$clusterIp}:5000"
            : self::ZOT_RELEASE_NAME.'.'.$ns.'.svc.cluster.local:5000';

        // Deploy DaemonSet to configure containerd on selected worker nodes for HTTP registry access.
        // DaemonSet only writes hosts.toml (hot-reload) + sets config_path in config.toml (no restart;
        // k3s/rke2: config untouched). Admin must run the command the DaemonSet log prints after
        // "containerd restart required" (registry:rolling-restart, --in-place on a shared cluster).
        $this->deployRegistryMirror($k8s, $ns, $address, $workersOnly, $kubeconfig);

        // Pre-pull required images on selected worker nodes
        $this->prepullImages($k8s, $ns, $workersOnly);

        Log::info('Zot registry installed', ['namespace' => $ns, 'address' => $address]);

        return ['address' => $address];
    }

    /**
     * Test PVC provisioning by creating a test PVC and checking it becomes Bound.
     *
     * @return array{success: bool, message: string}
     */
    public function testPvcBound(string $kubeconfig, string $storageClass, string $size, string $ns): array
    {
        $k8s = new KubernetesClient($kubeconfig);
        $this->ensureNamespace($k8s, $ns);

        $pvcManifest = $this->buildTestPvcManifest($storageClass, $size, $ns);
        $pvcName = $pvcManifest['metadata']['name'];
        $podManifest = $this->buildPvcConsumerPodManifest($pvcName, $ns);
        $podName = $podManifest['metadata']['name'];

        try {
            $k8s->applyResource($pvcManifest, $ns);
            $k8s->applyResource($podManifest, $ns);

            $bound = $this->waitFor(function () use ($k8s, $ns, $pvcName) {
                $pvc = $k8s->getResourceStatus('v1', 'PersistentVolumeClaim', $pvcName, $ns);

                return $pvc && ($pvc['status']['phase'] ?? '') === 'Bound';
            }, 60);

            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }
            $this->cleanupPvc($k8s, $pvcName, $ns);

            if (! $bound) {
                return ['success' => false, 'message' => "PVC provisioning failed: StorageClass '{$storageClass}' did not provision a volume within 60s"];
            }

            return ['success' => true, 'message' => 'PVC provisioning successful'];
        } catch (\Throwable $e) {
            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }
            $this->cleanupPvc($k8s, $pvcName, $ns);

            return ['success' => false, 'message' => 'PVC test error: '.$e->getMessage()];
        }
    }

    /**
     * Test S3 connectivity from inside the cluster.
     *
     * @return array{success: bool, message: string}
     */
    public function testS3FromPod(string $kubeconfig, array $s3Config, string $ns): array
    {
        $k8s = new KubernetesClient($kubeconfig);
        $this->ensureNamespace($k8s, $ns);

        $manifest = $this->buildS3TestPodManifest($s3Config, $ns);
        $podName = $manifest['metadata']['name'];

        return $this->runTestPod($k8s, $manifest, $podName, $ns, 'S3_OK', 'S3 connection failed');
    }

    /**
     * Test external registry connectivity from inside the cluster.
     *
     * @return array{success: bool, message: string}
     */
    public function testExternalRegistry(string $kubeconfig, string $url, ?string $user, ?string $pass, string $ns): array
    {
        $k8s = new KubernetesClient($kubeconfig);
        $this->ensureNamespace($k8s, $ns);

        $manifest = $this->buildRegistryTestPodManifest($url, $user, $pass, $ns);
        $podName = $manifest['metadata']['name'];

        return $this->runTestPod($k8s, $manifest, $podName, $ns, 'REGISTRY_OK', 'Registry unreachable');
    }

    /**
     * Uninstall Zot registry from a region.
     */
    public function uninstall(KnativeRegion $region): void
    {
        if ($region->registry_mode !== 'internal' || ! $region->kubeconfig) {
            $region->update([
                'registry_mode' => null, 'registry_address' => null,
                'registry_username' => null, 'registry_password' => null,
                'registry_storage_type' => null, 'registry_storage_config' => null,
                'registry_status' => null, 'registry_status_message' => null,
            ]);

            return;
        }

        $ns = $region->registry_namespace ?? self::ZOT_DEFAULT_NS;

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            try {
                $k8s->deleteResource('apps/v1', 'DaemonSet', 'runr-registry-mirror', $ns);
            } catch (\Throwable) {
            }

            $helm = new HelmService;
            $helm->uninstall(self::ZOT_RELEASE_NAME, $ns, $region->kubeconfig);

            $this->deleteNamespaceIfManaged($k8s, $ns);
        } catch (\Throwable $e) {
            Log::debug('Zot cleanup error', ['error' => $e->getMessage()]);
        }

        $region->update([
            'registry_mode' => null, 'registry_address' => null,
            'registry_username' => null, 'registry_password' => null,
            'registry_storage_type' => null, 'registry_storage_config' => null,
            'registry_status' => null, 'registry_status_message' => null,
        ]);

        Log::info('Zot registry removed', ['region' => $region->name]);
    }

    /**
     * Detect an existing Zot registry on the cluster.
     *
     * @return array{address: string}|null
     */
    public function detectExisting(string $kubeconfig, string $ns = self::ZOT_DEFAULT_NS): ?array
    {
        $k8s = new KubernetesClient($kubeconfig);

        if (! $k8s->getClusterResource('v1', 'Namespace', $ns)) {
            return null;
        }

        if ($this->zotReadyReplicas($k8s, $ns) === 0) {
            return null;
        }

        $svc = $k8s->getResourceStatus('v1', 'Service', self::ZOT_RELEASE_NAME, $ns);
        $clusterIp = $svc['spec']['clusterIP'] ?? null;
        $address = $clusterIp
            ? "{$clusterIp}:5000"
            : self::ZOT_RELEASE_NAME.'.'.$ns.'.svc.cluster.local:5000';

        return ['address' => $address];
    }

    // ── Sync upstreams (pull-through cache) ─────────────────────────────────

    /**
     * Whether the region's Zot syncs ghcr.io on demand into {zot}/ghcr.io/ — builds then take
     * their FROM images through it (KnativeRegion::buildBaseImageRef()). Read from the chart's
     * ConfigMap on every build, so a region whose Zot was not reconciled yet (or whose config
     * cannot be read) keeps pulling from ghcr.io directly instead of failing on a missing upstream.
     */
    public function syncsGhcr(KnativeRegion $region, KubernetesClient $k8s): bool
    {
        if ($region->registry_mode !== 'internal' || ! $region->has_registry) {
            return false;
        }

        try {
            $cm = $k8s->getResourceStatus('v1', 'ConfigMap', self::ZOT_CONFIG_MAP, $region->registry_namespace ?: self::ZOT_DEFAULT_NS);
        } catch (\Throwable) {
            return false;
        }

        return ZotManifests::hasGhcrUpstream($cm['data'][self::ZOT_CONFIG_KEY] ?? null);
    }

    /**
     * Brings the running Zot's config.json in $ns to RunR's sync upstreams
     * (ZotManifests::reconcileSyncConfig(): only RunR's entries/keys change) and restarts Zot —
     * only when the config changed. manifestCheckInterval is written only for a Zot image that
     * knows the key (older ones refuse to start on it).
     *
     * @return string|null what changed (in dry-run: would change), "skipped: …", null = up to date
     */
    public function reconcileSyncConfig(KubernetesClient $k8s, string $ns, bool $dryRun): ?string
    {
        $cmRef = "ConfigMap {$ns}/".self::ZOT_CONFIG_MAP;
        $cm = $k8s->getResourceStatus('v1', 'ConfigMap', self::ZOT_CONFIG_MAP, $ns);
        $json = $cm['data'][self::ZOT_CONFIG_KEY] ?? null;
        if (! is_string($json)) {
            return "skipped: {$cmRef} with ".self::ZOT_CONFIG_KEY.' not found';
        }

        [$kind, $workload] = $this->zotWorkload($k8s, $ns);
        if (! $workload) {
            return "skipped: Zot StatefulSet/Deployment {$ns}/".self::ZOT_RELEASE_NAME.' not found';
        }

        $interval = ZotManifests::supportsManifestCheckInterval(self::zotImage($workload));

        try {
            $new = ZotManifests::reconcileSyncConfig($json, $interval);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$cmRef}: ".self::ZOT_CONFIG_KEY." is not valid JSON ({$e->getMessage()})");
        }

        if ($new === null) {
            return null;
        }

        $what = "{$cmRef}: sync upstreams ghcr.io (→ {registry}/ghcr.io/, on demand"
            .($interval ? ', manifestCheckInterval '.ZotManifests::GHCR_MANIFEST_CHECK_INTERVAL : '; Zot older than '.ZotManifests::MANIFEST_CHECK_INTERVAL_SINCE.': no manifestCheckInterval')
            .') + Docker Hub';
        $workloadRef = "{$kind} {$ns}/".self::ZOT_RELEASE_NAME;

        if ($dryRun) {
            return "would update {$what}, then restart {$workloadRef}";
        }

        if (! $k8s->mergePatch('v1', 'ConfigMap', self::ZOT_CONFIG_MAP, $ns, ['data' => [self::ZOT_CONFIG_KEY => $new]])) {
            throw new \RuntimeException("merge patch of {$cmRef} was rejected");
        }

        // A new pod reads the ConfigMap as it is now; the annotation only changes with the config
        $restart = ['spec' => ['template' => ['metadata' => ['annotations' => [self::CONFIG_HASH_ANNOTATION => hash('sha256', $new)]]]]];
        if (! $k8s->mergePatch('apps/v1', $kind, self::ZOT_RELEASE_NAME, $ns, $restart)) {
            throw new \RuntimeException("{$cmRef} updated, but the restart of {$workloadRef} was rejected — restart it to load the new config");
        }

        return "updated {$what}; restarted {$workloadRef}";
    }

    /**
     * Has the region's Zot fetch the base images of function builds (RuntimeRegistry::
     * buildBaseImages(), through the ghcr.io / Docker Hub pull-through cache) before a build needs
     * them: Zot fetches an image it does not hold before it answers the build, minutes for a large
     * one, and a build waiting on that could run past its deadline. Images Zot holds already
     * (tags/list) are skipped; the rest go to one Job (ZotManifests::prewarmJob()) named after the
     * list, so a second run while it runs or after it succeeded starts nothing new; a Job that
     * failed is deleted and started again.
     *
     * @return string|null what was started (dry run: would be), "skipped: …", null = nothing to do
     */
    public function prewarmBuildBaseImages(KubernetesClient $k8s, KnativeRegion $region, bool $dryRun): ?string
    {
        if (! $this->syncsGhcr($region, $k8s)) {
            return 'skipped: the registry has no ghcr.io upstream (builds pull their base images from ghcr.io)';
        }

        $ns = $region->registry_namespace ?: self::ZOT_DEFAULT_NS;
        $prefix = rtrim((string) $region->registry_address, '/').'/';
        $missing = [];
        foreach (app(RuntimeRegistry::class)->buildBaseImages() as $image) {
            $local = $region->buildBaseImageRef($image, true);
            // Only images the builds take through this registry, by tag
            if (! str_starts_with($local, $prefix) || str_contains($local, '@')) {
                continue;
            }
            $path = substr($local, strlen($prefix));
            $colon = strrpos($path, ':');
            if ($colon === false || str_contains(substr($path, $colon), '/')) {
                continue;
            }
            [$repo, $tag] = [substr($path, 0, $colon), substr($path, $colon + 1)];
            if (! isset($missing["{$repo}:{$tag}"]) && ! $this->holdsTag($k8s, $ns, $repo, $tag)) {
                $missing["{$repo}:{$tag}"] = [$repo, $tag];
            }
        }

        if ($missing === []) {
            return null;
        }

        $list = implode(', ', array_keys($missing));
        $name = ZotManifests::PREWARM_JOB_PREFIX.substr(hash('sha256', $region->registry_address."\n".$list), 0, 10);
        $job = $k8s->getResourceStatus('batch/v1', 'Job', $name, $ns);
        // running or done: nothing new; failed: started again (it stays until its TTL otherwise)
        if ($job && ! self::jobFailed($job)) {
            return null;
        }
        $what = count($missing)." build base image(s) into the registry (Job {$ns}/{$name}".($job ? ', again: the previous run failed' : '')."): {$list}";
        if ($dryRun) {
            return "would fetch {$what}";
        }

        if ($job && ! $k8s->deleteResource('batch/v1', 'Job', $name, $ns, 'Background')) {
            throw new \RuntimeException("the failed Job {$ns}/{$name} could not be deleted");
        }
        if (! $k8s->createResource(ZotManifests::prewarmJob($name, $ns, (string) $region->registry_address, array_values($missing)), $ns)) {
            throw new \RuntimeException("Job {$ns}/{$name} could not be created");
        }

        return "fetching {$what}";
    }

    /** A Job that gave up: its Failed condition (backoff limit or deadline reached). */
    private static function jobFailed(array $job): bool
    {
        foreach ($job['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Failed' && ($condition['status'] ?? '') === 'True') {
                return true;
            }
        }

        return false;
    }

    /** Whether Zot holds repository:tag (tags/list — it does not fetch from upstream). */
    private function holdsTag(KubernetesClient $k8s, string $ns, string $repo, string $tag): bool
    {
        try {
            $response = $k8s->serviceProxyRequest('GET', $ns, self::ZOT_RELEASE_NAME, 5000, "v2/{$repo}/tags/list");
        } catch (\Throwable) {
            return false;
        }

        return $response->successful() && in_array($tag, (array) ($response->json('tags') ?? []), true);
    }

    /**
     * Zot workload: StatefulSet (PVC) or Deployment (S3).
     *
     * @return array{0: string, 1: ?array} [kind, object]
     */
    private function zotWorkload(KubernetesClient $k8s, string $ns): array
    {
        foreach (['StatefulSet', 'Deployment'] as $kind) {
            $obj = $k8s->getResourceStatus('apps/v1', $kind, self::ZOT_RELEASE_NAME, $ns);
            if ($obj) {
                return [$kind, $obj];
            }
        }

        return ['StatefulSet', null];
    }

    /** Image of the zot container (the chart names it after the chart: "zot"). */
    private static function zotImage(array $workload): ?string
    {
        $containers = $workload['spec']['template']['spec']['containers'] ?? [];
        $zot = collect($containers)->firstWhere('name', 'zot') ?? ($containers[0] ?? null);

        return $zot['image'] ?? null;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function runTestPod(KubernetesClient $k8s, array $manifest, string $podName, string $ns, string $successMarker, string $failPrefix): array
    {
        try {
            $k8s->applyResource($manifest, $ns);

            $completed = $this->waitFor(function () use ($k8s, $ns, $podName) {
                $status = $k8s->getResourceStatus('v1', 'Pod', $podName, $ns);
                if (! $status) {
                    return false;
                }
                $phase = $status['status']['phase'] ?? 'Pending';

                return in_array($phase, ['Succeeded', 'Failed']);
            }, 30);

            $logs = '';
            try {
                $logs = $k8s->getPodLogs($podName, $ns);
            } catch (\Throwable) {
            }
            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }

            if (! $completed) {
                return ['success' => false, 'message' => 'Timeout: test pod did not complete within 30s'];
            }

            if (str_contains($logs, $successMarker)) {
                return ['success' => true, 'message' => 'Connection successful'];
            }

            return ['success' => false, 'message' => "{$failPrefix}: {$logs}"];
        } catch (\Throwable $e) {
            try {
                $k8s->deleteResource('v1', 'Pod', $podName, $ns);
            } catch (\Throwable) {
            }

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function waitForZotReady(KubernetesClient $k8s, string $ns): void
    {
        $ready = $this->waitFor(fn () => $this->zotReadyReplicas($k8s, $ns) > 0, 120);

        if (! $ready) {
            throw new \RuntimeException('Zot deployment did not become ready within 120s');
        }
    }

    /**
     * Ready replicas of Zot: StatefulSet (PVC, persistence: true) or Deployment (S3, persistence: false).
     */
    private function zotReadyReplicas(KubernetesClient $k8s, string $ns): int
    {
        foreach (['StatefulSet', 'Deployment'] as $kind) {
            $obj = $k8s->getResourceStatus('apps/v1', $kind, self::ZOT_RELEASE_NAME, $ns);
            if ($obj) {
                return (int) ($obj['status']['readyReplicas'] ?? 0);
            }
        }

        return 0;
    }

    private function deployRegistryMirror(KubernetesClient $k8s, string $ns, string $address, bool $workersOnly = false, ?string $kubeconfig = null): void
    {
        // k3s/rke2 read hosts.toml from their own agent dir — pick the DaemonSet variant per node runtime
        $nodes = $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
        $flavor = ZotManifests::mirrorFlavorForNodes($nodes, $workersOnly);
        $inPlace = $this->mirrorRestartInPlace($nodes, $kubeconfig);
        $manifest = $this->buildRegistryMirrorDaemonSet($address, self::ZOT_RELEASE_NAME, $ns, $workersOnly, $flavor, $inPlace);
        $k8s->applyResource($manifest, $ns);

        $ready = $this->waitFor(function () use ($k8s, $ns) {
            $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', 'runr-registry-mirror', $ns);
            if (! $ds) {
                return false;
            }

            $desired = $ds['status']['desiredNumberScheduled'] ?? 0;
            $ready = $ds['status']['numberReady'] ?? 0;

            return $desired > 0 && $ready >= $desired;
        }, 120);

        if (! $ready) {
            Log::warning('Registry mirror DaemonSet not fully ready within 120s');
        }
    }

    /**
     * A containerd restart after the mirror changed config.toml cannot drain the nodes when RunR
     * runs in this cluster or the cluster has a single schedulable node → the log suggests --in-place.
     */
    private function mirrorRestartInPlace(array $nodes, ?string $kubeconfig): bool
    {
        $schedulable = app(KnativeNodeService::class)->schedulableCapacity($nodes)['nodes'];
        if (count($schedulable) <= 1) {
            return true;
        }

        return $kubeconfig !== null && app(RunrClusterIdentity::class)->isRunrClusterKubeconfig($kubeconfig);
    }

    /**
     * Patch Zot StatefulSet with fsGroup + init container for PVC permission fix.
     * Zot Helm chart does not support these values natively.
     */
    private function patchZotPermissions(KubernetesClient $k8s, string $ns): void
    {
        $k8s->mergePatch('apps/v1', 'StatefulSet', self::ZOT_RELEASE_NAME, $ns, [
            'spec' => ['template' => ['spec' => [
                'securityContext' => ['fsGroup' => 1000],
                'initContainers' => [[
                    'name' => 'fix-permissions',
                    'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
                    'command' => ['sh', '-c', 'chown -R 1000:1000 /var/lib/registry'],
                    'volumeMounts' => [['name' => self::ZOT_RELEASE_NAME.'-pvc', 'mountPath' => '/var/lib/registry']],
                    'securityContext' => ['runAsUser' => 0],
                ]],
            ]]],
        ]);
    }

    private function cleanupPvc(KubernetesClient $k8s, string $name, string $ns): void
    {
        try {
            $k8s->deleteResource('v1', 'PersistentVolumeClaim', $name, $ns);
        } catch (\Throwable) {
        }
    }
}
