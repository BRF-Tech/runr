<?php

namespace App\Services\Ai\Models;

use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\AiModelUpload;
use App\Models\KnativeRegion;
use App\Services\Ai\Engines\EngineCatalog;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Ai\Gpu\GpuRequest;
use App\Services\Ai\Gpu\GpuScheduling;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\ResourceReservation;
use App\Services\Manifests\KnativeManifests;

/**
 * Kubernetes manifests of model services and model files (pure: every input is an argument):
 *
 *  - knativeService(): the engine container as a cluster-local Knative Service. Online: the
 *    model id is downloaded at start into the region's model cache (HF_HOME on the model volume,
 *    HF_TOKEN from the organization's Secret). Offline: the snapshot is mounted read-only at
 *    /models/model, HF_HUB_OFFLINE / TRANSFORMERS_OFFLINE are set and networkPolicy() denies all
 *    egress except DNS.
 *  - mirrorJob(): huggingface_hub.snapshot_download of a pinned revision into the volume.
 *  - importJob(): a folder of the volume's import area (pvc) or an OCI artifact (ORAS) into a
 *    snapshot.
 *  - receiverPod() / receiverService(): the upload receiver of a chunked upload.
 *  - cleanupJob(): removes a snapshot's files.
 *  - tokenSecret(): HF_TOKEN.
 */
class ModelServiceManifests
{
    public const PORT = 8080;

    public const SNAPSHOT_MARKER = '.runr-snapshot.json';

    public function __construct(
        private ModelStorage $storage,
        private GpuScheduling $gpuScheduling,
        private GpuInventory $inventory,
    ) {}

    // ── Model service ───────────────────────────────────────────────────────

    /**
     * @param  string|null  $tokenSecret  name of the HF token Secret (online services; null = none)
     *
     * @throws \RuntimeException a sandbox that does not run GPUs (GpuScheduling)
     */
    public function knativeService(
        AiModelService $service,
        AiModel $model,
        ?AiModelSnapshot $snapshot,
        KnativeRegion $region,
        string $namespace,
        ?string $tokenSecret,
    ): array {
        $container = $this->engineContainer($service, $model, $snapshot, $region, $tokenSecret);
        $offline = $service->isOffline();

        $spec = [
            'timeoutSeconds' => max(1, (int) $service->timeout_seconds),
            'containers' => [$container],
            'volumes' => [$this->storage->podVolume($offline)],
        ];

        if (! $offline) {
            // The cache folder must be writable by any engine user (vLLM / TGI / TEI run as root,
            // hf-serve as uid 1000); lock files of another engine stay usable
            $spec['initContainers'] = [[
                'name' => 'models-init',
                'image' => $region->registryImageRef((string) config('ai.images.busybox')),
                'command' => ['sh', '-c', implode(' ; ', [
                    'mkdir -p '.ModelStorage::MOUNT_CACHE.'/hub/.locks',
                    'chmod a+rwx '.ModelStorage::MOUNT_CACHE.' '.ModelStorage::MOUNT_CACHE.'/hub '.ModelStorage::MOUNT_CACHE.'/hub/.locks',
                    'find '.ModelStorage::MOUNT_CACHE.'/hub -maxdepth 4 -type d -exec chmod a+rwx {} + 2>/dev/null',
                    'find '.ModelStorage::MOUNT_CACHE.'/hub/.locks -type f -exec chmod a+rw {} + 2>/dev/null',
                    'true',
                ])],
                'volumeMounts' => [[
                    'name' => ModelStorage::VOLUME_NAME,
                    'mountPath' => ModelStorage::MOUNT_CACHE,
                    'subPath' => $this->storage->cacheSubPath(),
                ]],
                'resources' => [
                    'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                    'limits' => ['cpu' => '100m', 'memory' => '64Mi'],
                ],
            ]];
        }

        if (! empty($region->selected_workers)) {
            $spec['tolerations'] = [[
                'key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule',
            ]];
            $spec['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        }

        // Model services run under the sandbox the region uses for CaaS projects
        $runtimeClass = $region->resolveRuntimeClass('caas');
        if ($runtimeClass) {
            $spec['runtimeClassName'] = $runtimeClass;
        }

        $annotations = [
            KnativeConstants::QUEUE_PROXY_CPU_LIMIT_ANNOTATION => KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M.'m',
            'runr.dev/deploy-timestamp' => now()->toIso8601String(),
            // Model download / load: allow the first start more than Knative's default 10 minutes
            'serving.knative.dev/progress-deadline' => (string) config('ai.gpu.progress_deadline', '1800s'),
        ];

        if ($gpu = GpuRequest::forService($service)) {
            $spec = $this->gpuScheduling->apply($spec, $gpu, $region);
            $annotations = array_merge($annotations, GpuScheduling::annotations());
        }

        $minScale = (int) $service->min_scale;
        if (! $service->scale_to_zero) {
            $annotations['autoscaling.knative.dev/min-scale'] = (string) max(1, $minScale);
        } elseif ($minScale > 0) {
            $annotations['autoscaling.knative.dev/min-scale'] = (string) $minScale;
        }
        if ((int) $service->max_scale > 0) {
            $annotations['autoscaling.knative.dev/max-scale'] = (string) $service->max_scale;
        }

        $labels = $this->labels($service);

        return [
            'apiVersion' => 'serving.knative.dev/v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $service->service_name,
                'namespace' => $namespace,
                'labels' => $labels + ['networking.knative.dev/visibility' => 'cluster-local'],
                'annotations' => ['runr.sh/model-service-id' => $service->id, 'runr.sh/model' => (string) ($model->hub_id ?? $model->name)],
            ],
            'spec' => [
                'template' => [
                    'metadata' => ['labels' => $labels, 'annotations' => $annotations],
                    'spec' => $spec,
                ],
            ],
        ];
    }

    /**
     * The engine container: image, arguments / env of the engine, the model reference (Hub id
     * online, /models/model offline), the cache or snapshot mount, resources and readiness.
     */
    public function engineContainer(AiModelService $service, AiModel $model, ?AiModelSnapshot $snapshot, KnativeRegion $region, ?string $tokenSecret): array
    {
        $engine = $service->engine;
        $offline = $service->isOffline();
        $gpu = GpuRequest::forService($service);
        $ref = $offline ? ModelStorage::MOUNT_SNAPSHOT : (string) $model->hub_id;
        $revision = $offline ? null : $model->pinnedRevision();
        $port = (int) ($service->port ?: self::PORT);

        [$image, $args, $env] = match ($engine) {
            'vllm' => $this->vllm($service, $ref, $revision, $gpu),
            'tgi' => $this->tgi($service, $ref, $revision, $gpu),
            'tei' => $this->tei($service, $region, $ref, $revision),
            'transformers', 'diffusers' => $this->hfServe($service, $model, $ref, $revision, $offline),
            default => [(string) $service->image, [], $this->modelRefEnv($model, $ref, $revision, $offline)],
        };

        if ($engine !== 'custom' && ! empty($service->image)) {
            $image = (string) $service->image;
        }

        // User arguments: appended to the engine's (custom: the only ones)
        $args = array_merge($args, array_map('strval', (array) ($service->args ?? [])));

        $cpu = (string) ($service->cpu ?: '1');
        $env = array_merge($env, self::threadEnv($cpu), $this->hubEnv($offline));

        $envList = [];
        foreach ($env as $name => $value) {
            $envList[] = ['name' => $name, 'value' => (string) $value];
        }
        if (! $offline && $tokenSecret) {
            $envList[] = ['name' => 'HF_TOKEN', 'valueFrom' => ['secretKeyRef' => ['name' => $tokenSecret, 'key' => 'HF_TOKEN', 'optional' => true]]];
            $envList[] = ['name' => 'HUGGING_FACE_HUB_TOKEN', 'valueFrom' => ['secretKeyRef' => ['name' => $tokenSecret, 'key' => 'HF_TOKEN', 'optional' => true]]];
        }

        // User env vars win over the engine's
        $user = (array) ($service->env_vars ?? []);
        if ($user) {
            $envList = array_values(array_filter($envList, fn ($e) => ! array_key_exists($e['name'], $user)));
            foreach ($user as $name => $value) {
                $envList[] = ['name' => (string) $name, 'value' => (string) $value];
            }
        }

        $memory = max(256, (int) $service->memory_mb).'Mi';

        $container = [
            'name' => 'model',
            'image' => $region->registryImageRef($image),
            'ports' => [['name' => 'http1', 'containerPort' => $port]],
            'env' => $envList,
            'resources' => [
                'requests' => ['cpu' => $cpu, 'memory' => $memory],
                'limits' => ['cpu' => $cpu, 'memory' => $memory],
            ],
            'volumeMounts' => [$offline
                ? ['name' => ModelStorage::VOLUME_NAME, 'mountPath' => ModelStorage::MOUNT_SNAPSHOT, 'subPath' => (string) $snapshot?->path, 'readOnly' => true]
                : ['name' => ModelStorage::VOLUME_NAME, 'mountPath' => ModelStorage::MOUNT_CACHE, 'subPath' => $this->storage->cacheSubPath()]],
            // An engine that exits without a termination message (a panic, a failed model load)
            // leaves the tail of its log there: the service status shows why it crash-loops
            'terminationMessagePolicy' => 'FallbackToLogsOnError',
        ];

        if ($args !== []) {
            $container['args'] = $args;
        }

        $health = $service->health_path ?: EngineCatalog::healthPath($engine);
        if ($health) {
            $container['readinessProbe'] = [
                'httpGet' => ['path' => $health],
                'periodSeconds' => 5,
                'timeoutSeconds' => 5,
                'failureThreshold' => 3,
            ];
        }

        return $container;
    }

    /**
     * Thread pools of the math libraries (OpenMP, MKL; PyTorch, ONNX Runtime and TEI's backends
     * use them) sized to the CPU limit, ceil(cpu): they default to the node's CPUs, and that
     * many threads under a smaller limit spend most periods throttled. hf-serve also reads the
     * cgroup quota when the variables are missing.
     *
     * @return array<string, string>
     */
    public static function threadEnv(string $cpu): array
    {
        $threads = (string) max(1, (int) ceil(ResourceReservation::parseCpu($cpu) / 1000));

        return ['OMP_NUM_THREADS' => $threads, 'MKL_NUM_THREADS' => $threads];
    }

    /** @return array{0: string, 1: list<string>, 2: array<string, string>} */
    private function vllm(AiModelService $service, string $ref, ?string $revision, ?GpuRequest $gpu): array
    {
        $args = ['--model', $ref, '--served-model-name', $service->served_name, '--host', '0.0.0.0', '--port', (string) self::PORT];
        if ($revision) {
            array_push($args, '--revision', $revision);
        }
        if ($gpu && $gpu->count > 1) {
            array_push($args, '--tensor-parallel-size', (string) $gpu->count);
        }

        return [(string) config('ai.images.vllm'), $args, []];
    }

    /** @return array{0: string, 1: list<string>, 2: array<string, string>} */
    private function tgi(AiModelService $service, string $ref, ?string $revision, ?GpuRequest $gpu): array
    {
        // No PORT: Knative sets it to the container port and refuses a Service that sets it
        $env = [
            'MODEL_ID' => $ref,
            'HOSTNAME' => '0.0.0.0',
        ];
        if ($revision) {
            $env['REVISION'] = $revision;
        }
        if ($gpu && $gpu->count > 1) {
            $env['NUM_SHARD'] = (string) $gpu->count;
        }

        return [(string) config('ai.images.tgi'), [], $env];
    }

    /** @return array{0: string, 1: list<string>, 2: array<string, string>} */
    private function tei(AiModelService $service, KnativeRegion $region, string $ref, ?string $revision): array
    {
        if ($service->usesGpu()) {
            $type = GpuRequest::forService($service)?->type;
            $arch = EngineCatalog::teiArch($this->inventory->computeCapability($region, $type));
            $image = str_replace('{arch}', $arch, (string) config('ai.images.tei_gpu'));
        } else {
            $image = (string) config('ai.images.tei_cpu');
        }

        // --served-model-name: the `model` of /v1/embeddings answers, as vLLM and hf-serve name it
        $args = ['--model-id', $ref, '--served-model-name', $service->served_name, '--hostname', '0.0.0.0', '--port', (string) self::PORT];
        if ($revision) {
            array_push($args, '--revision', $revision);
        }

        return [$image, $args, []];
    }

    /** @return array{0: string, 1: list<string>, 2: array<string, string>} */
    private function hfServe(AiModelService $service, AiModel $model, string $ref, ?string $revision, bool $offline): array
    {
        $cuda = $service->usesGpu() || $service->engine === 'diffusers';
        $image = (string) config($cuda ? 'ai.images.hf_serve_cuda' : 'ai.images.hf_serve');

        $env = $this->modelRefEnv($model, $ref, $revision, $offline) + [
            'RUNR_TASK' => (string) ($model->task ?? ''),
            'RUNR_ENGINE' => $service->engine,
            'RUNR_SERVED_NAME' => $service->served_name,
            'RUNR_DEVICE' => $cuda ? 'cuda' : 'cpu',
        ];

        return [$image, [], $env];
    }

    /** RUNR_MODEL_ID / _REVISION online, RUNR_MODEL_PATH offline (also for custom images). */
    private function modelRefEnv(AiModel $model, string $ref, ?string $revision, bool $offline): array
    {
        if ($offline) {
            return ['RUNR_MODEL_PATH' => $ref, 'RUNR_MODEL_ID' => (string) ($model->hub_id ?? $model->name)];
        }

        return array_filter(['RUNR_MODEL_ID' => $ref, 'RUNR_MODEL_REVISION' => (string) $revision], fn ($v) => $v !== '');
    }

    /** Hugging Face client env: the cache on the model volume, or offline mode. */
    private function hubEnv(bool $offline): array
    {
        $env = [
            'HF_HUB_DISABLE_TELEMETRY' => '1',
            'DO_NOT_TRACK' => '1',
        ];

        if ($offline) {
            return $env + [
                'HF_HUB_OFFLINE' => '1',
                'TRANSFORMERS_OFFLINE' => '1',
                'HF_DATASETS_OFFLINE' => '1',
            ];
        }

        $env += [
            'HF_HOME' => ModelStorage::MOUNT_CACHE,
            'HF_HUB_CACHE' => ModelStorage::MOUNT_CACHE.'/hub',
            'HUGGINGFACE_HUB_CACHE' => ModelStorage::MOUNT_CACHE.'/hub',
        ];

        $endpoint = (string) config('ai.huggingface.endpoint', 'https://huggingface.co');
        if ($endpoint !== '' && $endpoint !== 'https://huggingface.co') {
            $env['HF_ENDPOINT'] = $endpoint;
        }

        return $env;
    }

    /** Offline services: no egress but DNS (the engine never reaches the Hub or anything else). */
    public function networkPolicy(AiModelService $service, string $namespace): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => [
                'name' => self::networkPolicyName($service),
                'namespace' => $namespace,
                'labels' => $this->labels($service),
            ],
            'spec' => [
                'podSelector' => ['matchLabels' => [AiModelService::LABEL => $service->id]],
                'policyTypes' => ['Egress'],
                'egress' => [[
                    'to' => [[
                        'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => 'kube-system']],
                        'podSelector' => ['matchLabels' => ['k8s-app' => 'kube-dns']],
                    ]],
                    'ports' => [
                        ['protocol' => 'UDP', 'port' => 53],
                        ['protocol' => 'TCP', 'port' => 53],
                    ],
                ]],
            ],
        ];
    }

    public static function networkPolicyName(AiModelService $service): string
    {
        return substr($service->service_name, 0, 56).'-egress';
    }

    public function labels(AiModelService $service): array
    {
        return array_merge([
            'app.kubernetes.io/managed-by' => 'runr',
            'app.kubernetes.io/component' => 'model-service',
            AiModelService::LABEL => $service->id,
        ], KnativeManifests::contextLabels());
    }

    // ── Model files ─────────────────────────────────────────────────────────

    /** snapshot_download of the model's pinned revision into the snapshot folder. */
    public function mirrorJob(AiModelSnapshot $snapshot, AiModel $model, KnativeRegion $region, string $namespace, ?string $tokenSecret): array
    {
        $env = [
            ['name' => 'HF_HUB_DISABLE_TELEMETRY', 'value' => '1'],
            ['name' => 'HF_HUB_ENABLE_HF_TRANSFER', 'value' => '0'],
            ['name' => 'HF_HOME', 'value' => '/tmp/hf'],
        ];
        $endpoint = (string) config('ai.huggingface.endpoint', 'https://huggingface.co');
        if ($endpoint !== '' && $endpoint !== 'https://huggingface.co') {
            $env[] = ['name' => 'HF_ENDPOINT', 'value' => $endpoint];
        }
        if ($tokenSecret) {
            $env[] = ['name' => 'HF_TOKEN', 'valueFrom' => ['secretKeyRef' => ['name' => $tokenSecret, 'key' => 'HF_TOKEN', 'optional' => true]]];
        }

        $container = [
            'name' => 'mirror',
            'image' => $region->registryImageRef((string) config('ai.images.hf_serve')),
            'command' => ['python', '-m', 'runr_hf_serve', 'mirror', '--repo', (string) $model->hub_id, '--revision', $snapshot->revision, '--dest', '/snapshot'],
            'env' => $env,
            'volumeMounts' => [
                ['name' => ModelStorage::VOLUME_NAME, 'mountPath' => '/snapshot', 'subPath' => $snapshot->path],
                ['name' => 'tmp', 'mountPath' => '/tmp'],
            ],
        ];

        return $this->fileJob(self::jobName('mirror', $snapshot->id), $namespace, $region, $snapshot, [$container], [], [['name' => 'tmp', 'emptyDir' => new \stdClass]]);
    }

    /**
     * Import into a snapshot: `pvc` copies a folder of the organization's import area, `oci`
     * pulls an OCI artifact with ORAS (the region's own registry with its credentials and plain
     * HTTP for the in-cluster registry).
     *
     * @param  string|null  $registrySecret  docker config Secret for the OCI registry
     */
    public function importJob(AiModelSnapshot $snapshot, AiModel $model, KnativeRegion $region, string $namespace, ?string $registrySecret = null): array
    {
        $busybox = $region->registryImageRef((string) config('ai.images.busybox'));
        $volumes = [];
        $init = [];

        if ($snapshot->source === 'pvc') {
            $source = (string) $this->storage->importPath((string) $model->source_ref);
            $main = [
                'name' => 'import',
                'image' => $busybox,
                'command' => ['sh', '-c', 'set -e; cp -a /source/. /snapshot/; '.self::markerScript('pvc')],
                'volumeMounts' => [
                    ['name' => ModelStorage::VOLUME_NAME, 'mountPath' => '/source', 'subPath' => $source, 'readOnly' => true],
                    ['name' => ModelStorage::VOLUME_NAME, 'mountPath' => '/snapshot', 'subPath' => $snapshot->path],
                ],
            ];
        } else {
            $args = ['pull', (string) $model->source_ref, '--output', '/snapshot'];
            if ($this->isRegionRegistry($region, (string) $model->source_ref) && $region->registry_mode === 'internal') {
                $args[] = '--plain-http';
            }
            $mounts = [['name' => ModelStorage::VOLUME_NAME, 'mountPath' => '/snapshot', 'subPath' => $snapshot->path]];
            if ($registrySecret) {
                array_push($args, '--registry-config', '/auth/config.json');
                $mounts[] = ['name' => 'registry-auth', 'mountPath' => '/auth', 'readOnly' => true];
                $volumes[] = ['name' => 'registry-auth', 'secret' => ['secretName' => $registrySecret, 'items' => [['key' => '.dockerconfigjson', 'path' => 'config.json']]]];
            }

            $init[] = [
                'name' => 'pull',
                'image' => $region->registryImageRef((string) config('ai.images.oras')),
                'args' => $args,
                'volumeMounts' => $mounts,
            ];
            $main = [
                'name' => 'import',
                'image' => $busybox,
                'command' => ['sh', '-c', 'set -e; '.self::markerScript('oci')],
                'volumeMounts' => [['name' => ModelStorage::VOLUME_NAME, 'mountPath' => '/snapshot', 'subPath' => $snapshot->path]],
            ];
        }

        return $this->fileJob(self::jobName('import', $snapshot->id), $namespace, $region, $snapshot, [$main], $init, $volumes);
    }

    /** Removes a snapshot folder (its parent is mounted; the folder name is a sanitized revision). */
    public function cleanupJob(AiModelSnapshot $snapshot, KnativeRegion $region, string $namespace): array
    {
        $parent = dirname($snapshot->path);
        $leaf = basename($snapshot->path);

        $container = [
            'name' => 'cleanup',
            'image' => $region->registryImageRef((string) config('ai.images.busybox')),
            'command' => ['sh', '-c', 'rm -rf -- "/parent/'.ModelStorage::safeSegment($leaf).'"'],
            'volumeMounts' => [['name' => ModelStorage::VOLUME_NAME, 'mountPath' => '/parent', 'subPath' => $parent]],
        ];

        return $this->fileJob(self::jobName('rm', $snapshot->id), $namespace, $region, $snapshot, [$container]);
    }

    /** Upload receiver (hf-serve `receive`) with the target snapshot folder mounted. */
    public function receiverPod(AiModelUpload $upload, AiModelSnapshot $snapshot, KnativeRegion $region, string $namespace): array
    {
        $labels = $this->fileLabels($snapshot) + ['runr.sh/model-upload' => $upload->id];

        $spec = [
            'restartPolicy' => 'Never',
            'containers' => [[
                'name' => 'receiver',
                'image' => $region->registryImageRef((string) config('ai.images.hf_serve')),
                'command' => ['python', '-m', 'runr_hf_serve', 'receive', '--root', '/snapshot'],
                'env' => [['name' => 'PORT', 'value' => (string) self::PORT]],
                'ports' => [['containerPort' => self::PORT]],
                'readinessProbe' => ['httpGet' => ['path' => '/healthz', 'port' => self::PORT], 'periodSeconds' => 3],
                'securityContext' => ['runAsUser' => 0],
                'resources' => [
                    'requests' => ['cpu' => '100m', 'memory' => '256Mi'],
                    'limits' => ['cpu' => '1', 'memory' => '512Mi'],
                ],
                'volumeMounts' => [['name' => ModelStorage::VOLUME_NAME, 'mountPath' => '/snapshot', 'subPath' => $snapshot->path]],
            ]],
            'volumes' => [$this->storage->podVolume()],
            // The upload is abandoned after its TTL: the pod never outlives it
            'activeDeadlineSeconds' => max(3600, (int) config('ai.models.upload_ttl_hours', 24) * 3600),
        ];
        $spec = $this->withWorkerPlacement($spec, $region);

        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => ['name' => self::receiverName($upload), 'namespace' => $namespace, 'labels' => $labels],
            'spec' => $spec,
        ];
    }

    public function receiverService(AiModelUpload $upload, string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => self::receiverName($upload),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.sh/model-upload' => $upload->id],
            ],
            'spec' => [
                'selector' => ['runr.sh/model-upload' => $upload->id],
                'ports' => [['name' => 'http', 'port' => self::PORT, 'targetPort' => self::PORT]],
            ],
        ];
    }

    public static function receiverName(AiModelUpload $upload): string
    {
        return 'model-recv-'.strtolower($upload->id);
    }

    public function tokenSecret(string $name, string $namespace, string $token): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => array_merge(['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/component' => 'hf-token'], KnativeManifests::contextLabels()),
            ],
            'type' => 'Opaque',
            'stringData' => ['HF_TOKEN' => $token],
        ];
    }

    public static function jobName(string $kind, string $snapshotId): string
    {
        return substr('model-'.$kind.'-'.strtolower($snapshotId), 0, 63);
    }

    /**
     * Writes .runr-snapshot.json into /snapshot and prints the RUNR_SNAPSHOT line the status check
     * reads (size in bytes from du -sk, file count).
     */
    private static function markerScript(string $source): string
    {
        return 'files=$(find /snapshot -type f ! -name '.self::SNAPSHOT_MARKER.' | wc -l); '
            .'kb=$(du -sk /snapshot | cut -f1); size=$((kb * 1024)); '
            .'printf \'{"source":"'.$source.'","size_bytes":%s,"files_count":%s,"completed_at":"%s"}\' "$size" "$files" "$(date -u +%FT%TZ)" > /snapshot/'.self::SNAPSHOT_MARKER.'; '
            .'echo "RUNR_SNAPSHOT $(cat /snapshot/'.self::SNAPSHOT_MARKER.')"';
    }

    private function fileJob(string $name, string $namespace, KnativeRegion $region, AiModelSnapshot $snapshot, array $containers, array $initContainers = [], array $volumes = []): array
    {
        foreach ($containers as &$container) {
            // Writes into folders the kubelet created as root
            $container['securityContext'] = ['runAsUser' => 0];
            $container['resources'] ??= [
                'requests' => ['cpu' => '250m', 'memory' => '512Mi'],
                'limits' => ['cpu' => '2', 'memory' => '2Gi'],
            ];
        }
        unset($container);
        foreach ($initContainers as &$container) {
            $container['securityContext'] = ['runAsUser' => 0];
            $container['resources'] ??= [
                'requests' => ['cpu' => '250m', 'memory' => '256Mi'],
                'limits' => ['cpu' => '2', 'memory' => '1Gi'],
            ];
        }
        unset($container);

        $spec = [
            'restartPolicy' => 'Never',
            'containers' => $containers,
            'volumes' => array_merge([$this->storage->podVolume()], $volumes),
        ];
        if ($initContainers) {
            $spec['initContainers'] = $initContainers;
        }
        $spec = $this->withWorkerPlacement($spec, $region);

        $labels = $this->fileLabels($snapshot);

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 1,
                'activeDeadlineSeconds' => (int) config('ai.models.mirror_deadline_seconds', 7200),
                'ttlSecondsAfterFinished' => 86400,
                'template' => ['metadata' => ['labels' => $labels], 'spec' => $spec],
            ],
        ];
    }

    private function fileLabels(AiModelSnapshot $snapshot): array
    {
        return array_merge([
            'app.kubernetes.io/managed-by' => 'runr',
            'app.kubernetes.io/component' => 'model-files',
            'runr.sh/model-snapshot' => $snapshot->id,
        ], KnativeManifests::contextLabels());
    }

    /** Dedicated workers: model file pods run where the projects run (the volume is reachable there). */
    private function withWorkerPlacement(array $spec, KnativeRegion $region): array
    {
        if (! empty($region->selected_workers)) {
            $spec['tolerations'] = [['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule']];
            $spec['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        }

        return $spec;
    }

    private function isRegionRegistry(KnativeRegion $region, string $reference): bool
    {
        $address = (string) $region->registry_address;
        if ($address === '') {
            return false;
        }

        $host = preg_replace('#^https?://#', '', rtrim($address, '/'));

        return str_starts_with($reference, $host.'/');
    }
}
