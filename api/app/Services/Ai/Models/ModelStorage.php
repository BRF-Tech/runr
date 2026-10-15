<?php

namespace App\Services\Ai\Models;

use App\Models\AiModel;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\Manifests\KnativeManifests;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * The model volume of a region: one PersistentVolumeClaim (`runr-models`) in the region's project
 * namespace that holds
 *
 *   hf/<tenant>/                          Hugging Face cache of online model services (HF_HOME)
 *   snapshots/<tenant>/<model>/<revision> offline copies (mirror, upload, import)
 *   imports/<tenant>/                     drop zone for imports from a path in the volume
 *
 * Pods mount sub-paths only, so an organization never sees another one's files. Why a volume and
 * not the registry (OCI artifacts): engines read model directories from a file system, a
 * registry copy would have to be unpacked onto a volume before every start (twice the disk and a
 * slower cold start), multi-GB weights are the kind of blob registry retention trims, and every
 * Knative version mounts PVCs. OCI artifacts are the transport for air-gapped imports
 * (ORAS → volume), see SnapshotService.
 *
 * Settings (region model_storage): storage_class (empty = the cluster default), size_gi (100),
 * access_mode ReadWriteMany (pods on any node; ReadWriteOnce for single-node clusters).
 */
class ModelStorage
{
    public const MOUNT_CACHE = '/models/hf';

    public const MOUNT_SNAPSHOT = '/models/model';

    public const VOLUME_NAME = 'runr-models';

    public function pvcName(): string
    {
        return (string) config('ai.models.pvc_name', 'runr-models');
    }

    /** Effective settings of a region's model volume. */
    public function settings(KnativeRegion $region): array
    {
        $stored = (array) ($region->model_storage ?? []);

        return [
            'storage_class' => (string) ($stored['storage_class'] ?? config('ai.models.storage_class', '')),
            'size_gi' => max(1, (int) ($stored['size_gi'] ?? config('ai.models.storage_size_gi', 100))),
            'access_mode' => in_array($stored['access_mode'] ?? null, ['ReadWriteMany', 'ReadWriteOnce'], true)
                ? $stored['access_mode']
                : (string) config('ai.models.storage_access_mode', 'ReadWriteMany'),
        ];
    }

    /** Folder of the current organization: its tenant key, "default" without tenancy. */
    public function tenantKey(): string
    {
        $tenantId = RuntimeRegistry::currentTenantId();
        if ($tenantId === null) {
            return 'default';
        }

        $labels = KnativeManifests::contextLabels();
        $key = $labels['runr/tenant'] ?? $tenantId;

        return strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $key));
    }

    public function cacheSubPath(): string
    {
        return 'hf/'.$this->tenantKey();
    }

    public function snapshotSubPath(AiModel $model, string $revision): string
    {
        return 'snapshots/'.$this->tenantKey().'/'.$model->storageKey().'/'.self::safeSegment($revision);
    }

    public function importsSubPath(): string
    {
        return 'imports/'.$this->tenantKey();
    }

    /** A path below the organization's import folder; null when it leaves it. */
    public function importPath(string $relative): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains('/'.$relative.'/', '/../') || str_contains($relative, "\0")) {
            return null;
        }

        return $this->importsSubPath().'/'.$relative;
    }

    public function pvcManifest(KnativeRegion $region, string $namespace): array
    {
        $settings = $this->settings($region);

        $spec = [
            'accessModes' => [$settings['access_mode']],
            'resources' => ['requests' => ['storage' => $settings['size_gi'].'Gi']],
        ];
        if ($settings['storage_class'] !== '') {
            $spec['storageClassName'] = $settings['storage_class'];
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => [
                'name' => $this->pvcName(),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/component' => 'models'],
            ],
            'spec' => $spec,
        ];
    }

    /**
     * Create the volume when it does not exist (a PVC's spec is immutable except for a larger
     * size: an existing claim is only grown).
     */
    public function ensure(KnativeRegion $region, KubernetesClient $k8s, string $namespace): void
    {
        $existing = $k8s->getResourceStatus('v1', 'PersistentVolumeClaim', $this->pvcName(), $namespace);
        $manifest = $this->pvcManifest($region, $namespace);

        if (! $existing) {
            if (! $k8s->createResource($manifest, $namespace)) {
                throw new \RuntimeException(__('ai.models.storage_create_failed', ['name' => $this->pvcName()]));
            }

            // A region's first model volume: the hf-serve image onto the project nodes too
            try {
                app(ModelImagePrepull::class)->forStorage($region);
            } catch (\Throwable) {
                // an optimisation only
            }

            return;
        }

        $current = (string) ($existing['spec']['resources']['requests']['storage'] ?? '');
        $wanted = $manifest['spec']['resources']['requests']['storage'];
        if (self::gibibytes($wanted) > self::gibibytes($current)) {
            $k8s->strategicMergePatch('v1', 'PersistentVolumeClaim', $this->pvcName(), $namespace, [
                'spec' => ['resources' => ['requests' => ['storage' => $wanted]]],
            ]);
        }
    }

    /**
     * Status of the volume for the UI.
     *
     * @return array{pvc_name: string, storage_class: string, size_gi: int, access_mode: string, status: string, message: ?string, capacity: ?string}
     */
    public function status(KnativeRegion $region): array
    {
        $settings = $this->settings($region);
        $result = $settings + ['pvc_name' => $this->pvcName(), 'status' => 'absent', 'message' => null, 'capacity' => null];

        if ($region->is_demo || ! $region->kubeconfig) {
            return $result;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?: $region->name;
            $pvc = $k8s->getResourceStatus('v1', 'PersistentVolumeClaim', $this->pvcName(), $namespace);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()] + $result;
        }

        if (! $pvc) {
            return $result;
        }

        $phase = self::phase($pvc);

        return [
            'status' => $phase === 'Bound' ? 'bound' : ($phase === 'Lost' ? 'error' : 'pending'),
            'message' => $phase === 'Bound' ? null : $this->unboundMessage($k8s, $namespace, $phase),
            'capacity' => $pvc['status']['capacity']['storage'] ?? null,
            'storage_class' => (string) ($pvc['spec']['storageClassName'] ?? $settings['storage_class']),
            'access_mode' => (string) ($pvc['spec']['accessModes'][0] ?? $settings['access_mode']),
        ] + $result;
    }

    /** Why the volume can not be mounted yet; null while it is bound or there is no claim. */
    public function notBoundMessage(KubernetesClient $k8s, string $namespace): ?string
    {
        $pvc = $k8s->getResourceStatus('v1', 'PersistentVolumeClaim', $this->pvcName(), $namespace);
        if (! $pvc || self::phase($pvc) === 'Bound') {
            return null;
        }

        return $this->unboundMessage($k8s, $namespace, self::phase($pvc));
    }

    /**
     * "The model volume runr-models is Pending: <reason>". The reason is the claim's newest
     * Warning event (ProvisioningFailed: e.g. a ReadWriteMany claim the local-path provisioner
     * can not provision, a StorageClass that does not exist), else its newest event
     * (WaitForFirstConsumer, ExternalProvisioning), else a pointer to the settings.
     */
    private function unboundMessage(KubernetesClient $k8s, string $namespace, string $phase): string
    {
        try {
            $events = $k8s->listEvents($namespace, 'PersistentVolumeClaim', $this->pvcName());
        } catch (\Throwable) {
            $events = [];
        }
        usort($events, fn (array $a, array $b) => self::eventTime($b) <=> self::eventTime($a));
        $event = collect($events)->first(fn (array $e) => ($e['type'] ?? null) === 'Warning') ?? ($events[0] ?? null);
        $reason = trim((string) ($event['message'] ?? ''));

        return __('ai.models.volume_not_bound', [
            'name' => $this->pvcName(),
            'phase' => $phase,
            'reason' => $reason !== '' ? $reason : __('ai.models.volume_no_events'),
        ]);
    }

    private static function phase(array $pvc): string
    {
        return ucfirst(strtolower((string) ($pvc['status']['phase'] ?? 'Pending')));
    }

    /** When an event last happened (core/v1 lastTimestamp, events.k8s.io eventTime, creation). */
    private static function eventTime(array $event): int
    {
        $at = $event['lastTimestamp'] ?? $event['eventTime'] ?? $event['metadata']['creationTimestamp'] ?? null;

        return is_string($at) ? (int) strtotime($at) : 0;
    }

    /** Volume entry of a pod spec. */
    public function podVolume(bool $readOnly = false): array
    {
        return [
            'name' => self::VOLUME_NAME,
            'persistentVolumeClaim' => array_filter([
                'claimName' => $this->pvcName(),
                'readOnly' => $readOnly ?: null,
            ]),
        ];
    }

    public static function safeSegment(string $value): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value);

        return trim($value, '.-') ?: 'main';
    }

    private static function gibibytes(string $quantity): float
    {
        if (! preg_match('/^(\d+(?:\.\d+)?)([KMGTP]i?)?$/', trim($quantity), $m)) {
            return 0.0;
        }

        $factor = match ($m[2] ?? '') {
            'Ki' => 1 / (1024 ** 2), 'Mi' => 1 / 1024, 'Gi' => 1, 'Ti' => 1024, 'Pi' => 1024 ** 2,
            'K' => 1e3 / 1024 ** 3, 'M' => 1e6 / 1024 ** 3, 'G' => 1e9 / 1024 ** 3, 'T' => 1e12 / 1024 ** 3,
            default => 1 / 1024 ** 3,
        };

        return (float) $m[1] * $factor;
    }
}
