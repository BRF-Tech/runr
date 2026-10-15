<?php

namespace App\Services\Images;

use App\Models\ImageUpload;
use App\Models\KnativeRegion;

/**
 * Kubernetes objects of an upload's receiver in the region (runtime/image-upload `serve`):
 *
 *  - a Job (one pod, never restarted) that receives the chunks into an emptyDir, verifies the
 *    file, pushes it to <registry>/<registry repository>:<tag> and keeps answering /status until
 *    RunR removes it (activeDeadlineSeconds = the upload TTL as the safety net);
 *  - a ClusterIP Service in front of it — the target of the API server's service proxy, through
 *    which the RunR API forwards every chunk (the region needs no route to RunR).
 *
 * The pod has no Kubernetes API access, runs as a non-root user with a read-only root filesystem,
 * no capabilities and the region's build RuntimeClass (gVisor / Kata when builds are sandboxed:
 * untrusted archive content is unpacked here). Disk: the emptyDir holds the file and, for OCI
 * layouts and gzip'ed archives, its extracted copy.
 */
class ImageUploadManifests
{
    public const PORT = 8080;

    public static function receiverName(ImageUpload $upload): string
    {
        return 'image-recv-'.strtolower($upload->id);
    }

    public static function labels(ImageUpload $upload): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'runr',
            'app.kubernetes.io/component' => 'image-upload',
            'runr.sh/image-upload' => strtolower($upload->id),
        ];
    }

    /** Target of the push: <registry>/<prefix>/<owner>/<repository>:<tag>. */
    public static function target(ImageUpload $upload, KnativeRegion $region): string
    {
        return rtrim((string) $region->registry_address, '/').'/'.ImageName::registryRepository($upload->owner_key, $upload->repository).':'.$upload->tag;
    }

    /** emptyDir size: the file, plus an extracted copy (OCI layout, gunzip), plus headroom. */
    public static function workVolumeSize(ImageUpload $upload): string
    {
        $bytes = $upload->format === 'wasm' ? $upload->size_bytes : 2 * $upload->size_bytes;

        return (int) ceil(($bytes + 512 * 1024 * 1024) / (1024 * 1024)).'Mi';
    }

    public function job(ImageUpload $upload, KnativeRegion $region, string $namespace): array
    {
        $labels = self::labels($upload);
        $cfg = (array) config('images.receiver', []);
        $disk = self::workVolumeSize($upload);

        $env = array_values(array_filter([
            ['name' => 'PORT', 'value' => (string) self::PORT],
            ['name' => 'RUNR_UPLOAD_DIR', 'value' => '/work'],
            ['name' => 'RUNR_UPLOAD_SIZE', 'value' => (string) $upload->size_bytes],
            $upload->sha256 ? ['name' => 'RUNR_UPLOAD_SHA256', 'value' => $upload->sha256] : null,
            ['name' => 'RUNR_UPLOAD_TARGET', 'value' => self::target($upload, $region)],
            ['name' => 'RUNR_UPLOAD_FORMAT', 'value' => $upload->format],
            ['name' => 'RUNR_UPLOAD_MAX_CHUNK', 'value' => (string) $upload->chunk_size],
            ['name' => 'RUNR_UPLOAD_PUSH_TIMEOUT', 'value' => (string) ($cfg['push_timeout'] ?? 3600)],
            // RunR's internal registry: plain HTTP inside the cluster, as Kaniko pushes builds
            ['name' => 'RUNR_REGISTRY_PLAIN_HTTP', 'value' => 'true'],
        ]));

        $pod = array_filter([
            'restartPolicy' => 'Never',
            'automountServiceAccountToken' => false,
            'enableServiceLinks' => false,
            'runtimeClassName' => $region->resolveBuildRuntimeClass(),
            'securityContext' => [
                'runAsNonRoot' => true,
                'runAsUser' => 65532,
                'runAsGroup' => 65532,
                'fsGroup' => 65532,
                'seccompProfile' => ['type' => 'RuntimeDefault'],
            ],
            'containers' => [[
                'name' => 'receiver',
                'image' => $region->registryImageRef((string) config('images.upload_image')),
                'args' => ['serve'],
                'env' => $env,
                'ports' => [['containerPort' => self::PORT, 'name' => 'http']],
                'readinessProbe' => ['httpGet' => ['path' => '/healthz', 'port' => self::PORT], 'periodSeconds' => 2],
                'securityContext' => [
                    'allowPrivilegeEscalation' => false,
                    'readOnlyRootFilesystem' => true,
                    'capabilities' => ['drop' => ['ALL']],
                ],
                'resources' => [
                    'requests' => ['cpu' => $cfg['cpu_request'] ?? '100m', 'memory' => $cfg['memory_request'] ?? '128Mi', 'ephemeral-storage' => $disk],
                    'limits' => ['cpu' => $cfg['cpu_limit'] ?? '2', 'memory' => $cfg['memory_limit'] ?? '1Gi', 'ephemeral-storage' => $disk],
                ],
                'volumeMounts' => [['name' => 'work', 'mountPath' => '/work']],
                'terminationMessagePolicy' => 'FallbackToLogsOnError',
            ]],
            'volumes' => [['name' => 'work', 'emptyDir' => ['sizeLimit' => $disk]]],
        ], fn ($v) => $v !== null);

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => self::receiverName($upload), 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 0,
                // the upload is abandoned after its TTL: the receiver never outlives it
                'activeDeadlineSeconds' => max(3600, (int) config('images.upload_ttl_hours', 24) * 3600),
                'template' => ['metadata' => ['labels' => $labels], 'spec' => $pod],
            ],
        ];
    }

    public function service(ImageUpload $upload, string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => self::receiverName($upload), 'namespace' => $namespace, 'labels' => self::labels($upload)],
            'spec' => [
                'selector' => ['runr.sh/image-upload' => strtolower($upload->id)],
                'ports' => [['name' => 'http', 'port' => self::PORT, 'targetPort' => self::PORT]],
            ],
        ];
    }
}
