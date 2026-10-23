<?php

namespace App\Services\Ai\Models;

use App\Jobs\Ai\CheckModelServiceReadyJob;
use App\Jobs\Ai\DeployModelServiceJob;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\KnativeRegion;
use App\Services\Ai\Engines\EngineCatalog;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Ai\Hub\HubToken;
use App\Services\Helpers\ResourceReservation;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\KubernetesClient;
use App\Support\DeployGate;
use Illuminate\Support\Facades\Log;

/**
 * Runs model services in their region: deploy (model volume, token Secret, offline
 * NetworkPolicy, Knative Service), stop, readiness, logs and playground calls.
 *
 * Offline services need a ready snapshot of the model in the region; without one the deploy
 * starts the mirror (SnapshotService) and the service waits (waiting_snapshot) until the
 * snapshot is ready, then deploys.
 */
class ModelServiceDeployer
{
    /** Waiting reasons of a container that Kubernetes retries without getting further. */
    private const WAITING_PROBLEMS = [
        'ImagePullBackOff', 'ErrImagePull', 'InvalidImageName', 'ErrImageNeverPull',
        'CreateContainerConfigError', 'CreateContainerError', 'RunContainerError',
    ];

    public function __construct(
        private ModelServiceManifests $manifests,
        private ModelStorage $storage,
        private HubToken $token,
        private SnapshotService $snapshots,
        private ModelImagePrepull $prepull,
    ) {}

    public function deploy(AiModelService $service): void
    {
        $service->loadMissing('model', 'region');
        $region = $service->region;
        $model = $service->model;

        if ($blocked = DeployGate::blockedReason()) {
            $this->fail($service, $blocked);

            return;
        }

        if (! $region || ! $model) {
            $this->fail($service, __('ai.models.region_missing'));

            return;
        }

        if ($region->is_demo) {
            $service->update(['status' => AiModelService::STATUS_READY, 'status_message' => null, 'deployed_at' => now()]);

            return;
        }

        if (! $region->kubeconfig || ! $region->is_active) {
            $this->fail($service, __('ai.models.region_unavailable', ['region' => $region->name]));

            return;
        }

        $snapshot = null;
        if ($service->isOffline()) {
            $snapshot = $model->readySnapshotIn($region->id);
            if (! $snapshot) {
                $pending = $this->snapshots->ensureFor($model, $region);
                $service->update([
                    'status' => AiModelService::STATUS_WAITING_SNAPSHOT,
                    'status_message' => __('ai.models.waiting_snapshot', ['status' => $pending->status]),
                    'snapshot_id' => $pending->id,
                ]);

                return;
            }
        } elseif (! $model->isHub()) {
            $this->fail($service, __('ai.models.online_needs_hub'));

            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $service->namespace();

            // the Knative Service below mounts the model volume: the PVC features must be in force
            app(GpuInstallService::class)->enableKnativeFeatures($k8s, $region, waitUntilEffective: true);
            $this->storage->ensure($region, $k8s, $namespace);

            $secret = $service->isOffline() ? null : $this->token->syncSecret($region, $k8s, $namespace);

            if ($service->isOffline()) {
                if (! $k8s->applyResource($this->manifests->networkPolicy($service, $namespace), $namespace)) {
                    throw new \RuntimeException(__('ai.models.apply_failed', ['kind' => 'NetworkPolicy']));
                }
            } else {
                try {
                    $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', ModelServiceManifests::networkPolicyName($service), $namespace);
                } catch (\Throwable) {
                }
            }

            $manifest = $this->manifests->knativeService($service, $model, $snapshot, $region, $namespace, $secret);
            if (! $k8s->applyResource($manifest, $namespace)) {
                throw new \RuntimeException(__('ai.models.apply_failed', ['kind' => 'Knative Service']));
            }

            // The engine image onto the other nodes the service can run on (scale-out, a
            // rescheduled pod, a start from zero elsewhere)
            $this->prepullImages($service);

            // Region capacity counts the Kata pod overhead of the RuntimeClass the service runs with
            $service->forceFill(['runtime_class' => ResourceReservation::runtimeClassOfPodSpec($manifest['spec']['template']['spec'] ?? [])]);
            $service->update([
                'status' => AiModelService::STATUS_DEPLOYING,
                'status_message' => null,
                'snapshot_id' => $snapshot?->id,
                'deployed_at' => now(),
            ]);

            CheckModelServiceReadyJob::dispatch($service->id)->delay(now()->addSeconds(10));
        } catch (\Throwable $e) {
            Log::error('Model service deploy failed', ['service' => $service->name, 'error' => $e->getMessage()]);
            $this->fail($service, $e->getMessage());
        }
    }

    /** Start pre-pulling the service's images (ModelImagePrepull); never fails the caller. */
    public function prepullImages(AiModelService $service): void
    {
        try {
            $this->prepull->forService($service);
        } catch (\Throwable $e) {
            Log::warning('Model image pre-pull not started', ['service' => $service->name, 'error' => $e->getMessage()]);
        }
    }

    /** Remove the Knative Service (and the offline NetworkPolicy); the service keeps its settings. */
    public function stop(AiModelService $service): bool
    {
        $ok = $this->teardown($service);
        $service->update(['status' => AiModelService::STATUS_STOPPED, 'status_message' => null]);

        return $ok;
    }

    public function teardown(AiModelService $service): bool
    {
        $region = $service->region;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return true;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $service->namespace();
            $ok = $k8s->deleteResource('serving.knative.dev/v1', 'Service', $service->service_name, $namespace);
            try {
                $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', ModelServiceManifests::networkPolicyName($service), $namespace);
            } catch (\Throwable) {
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('Model service teardown failed', ['service' => $service->name, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Readiness of the Knative Service: [ready (true/false/null = still starting), message,
     * reason]. While it is not ready the pods say why when Kubernetes cannot start them
     * (podProblem(): CrashLoopBackOff, ImagePullBackOff, …): that reason, and its message in
     * place of Knative's progress message.
     *
     * @return array{0: ?bool, 1: ?string, 2: ?string}
     */
    public function readiness(AiModelService $service): array
    {
        $region = $service->region;
        if (! $region || ! $region->kubeconfig) {
            return [false, __('ai.models.region_unavailable', ['region' => $region?->name ?? '?']), null];
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $ksvc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $service->service_name, $service->namespace());
        if (! $ksvc) {
            return [null, null, null];
        }

        [$ready, $message] = self::readinessOf($ksvc);
        if ($ready === true) {
            return [true, null, null];
        }

        try {
            $pods = $k8s->listResources('v1', 'Pod', $service->namespace(), [AiModelService::LABEL => $service->id])['items'] ?? [];
            $problem = self::podProblem($pods, (int) $service->memory_mb);
        } catch (\Throwable) {
            $problem = null;
        }

        if (! $problem) {
            return [$ready, $message, null];
        }

        // A failed revision keeps Knative's message (it names the revision and the cause)
        return [$ready, $ready === false && $message ? $message : $problem['message'], $problem['reason']];
    }

    /**
     * Why the pods of a model service do not start, from the first pod with a reason:
     *
     *  - a container (init containers first) waiting with a reason Kubernetes only retries:
     *    CrashLoopBackOff, with the last termination (exit code, reason and the termination
     *    message: the engine's log tail through terminationMessagePolicy FallbackToLogsOnError),
     *    ImagePullBackOff / ErrImagePull / InvalidImageName, CreateContainerConfigError …;
     *  - a container that was OOMKilled and is not ready (killed at the memory limit);
     *  - a pod the scheduler cannot place (PodScheduled False, e.g. Insufficient memory).
     *
     * Null while the pods are only starting (ContainerCreating, PodInitializing, loading).
     *
     * @param  list<array>  $pods
     * @return array{reason: string, message: string}|null
     */
    public static function podProblem(array $pods, int $memoryMb = 0): ?array
    {
        foreach ($pods as $pod) {
            $statuses = array_merge($pod['status']['initContainerStatuses'] ?? [], $pod['status']['containerStatuses'] ?? []);

            foreach ($statuses as $status) {
                $container = (string) ($status['name'] ?? '?');
                $waiting = $status['state']['waiting'] ?? null;
                $reason = (string) ($waiting['reason'] ?? '');
                $last = $status['lastState']['terminated'] ?? null;

                if ($reason === 'CrashLoopBackOff' || (($last['reason'] ?? '') === 'OOMKilled' && ! ($status['ready'] ?? false))) {
                    return self::crashProblem($reason ?: 'OOMKilled', $container, (int) ($status['restartCount'] ?? 0), $last, $memoryMb);
                }

                if (in_array($reason, self::WAITING_PROBLEMS, true)) {
                    $detail = self::clean((string) ($waiting['message'] ?? ''), 600);

                    return [
                        'reason' => $reason,
                        'message' => __(str_contains($reason, 'Image') ? 'ai.models.pod_image' : 'ai.models.pod_waiting', [
                            'reason' => $reason,
                            'container' => $container,
                            'message' => $detail !== '' ? $detail : $reason,
                        ]),
                    ];
                }
            }

            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? '') === 'PodScheduled' && ($condition['status'] ?? '') === 'False') {
                    return [
                        'reason' => (string) ($condition['reason'] ?? 'Unschedulable'),
                        'message' => __('ai.models.pod_unschedulable', ['message' => self::clean((string) ($condition['message'] ?? ''), 600)]),
                    ];
                }
            }
        }

        return null;
    }

    /** @return array{reason: string, message: string} */
    private static function crashProblem(string $reason, string $container, int $restarts, ?array $last, int $memoryMb): array
    {
        $message = __('ai.models.pod_crash_loop', [
            'reason' => $reason,
            'container' => $container,
            'restarts' => $restarts,
            'code' => $last['exitCode'] ?? '?',
            'why' => $last['reason'] ?? 'Error',
        ]);

        if (($last['reason'] ?? '') === 'OOMKilled') {
            $message .= ' '.__('ai.models.pod_oom', ['memory' => $memoryMb]);
        }

        $output = self::clean((string) ($last['message'] ?? ''), 1500);
        if ($output !== '') {
            $message .= "\n".$output;
        }

        return ['reason' => $reason, 'message' => $message];
    }

    /** A Kubernetes message without colour codes: its last $max characters. */
    private static function clean(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text));

        return mb_strlen($text) > $max ? '…'.ltrim(mb_substr($text, -$max)) : $text;
    }

    /**
     * Ready condition of a Knative Service: True → ready, False with a terminal reason → failed,
     * anything else → still starting.
     *
     * @return array{0: ?bool, 1: ?string}
     */
    public static function readinessOf(array $ksvc): array
    {
        foreach ($ksvc['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') !== 'Ready') {
                continue;
            }

            $status = $condition['status'] ?? 'Unknown';
            $message = $condition['message'] ?? ($condition['reason'] ?? null);

            if ($status === 'True') {
                return [true, null];
            }
            if ($status === 'False') {
                return [false, $message];
            }

            return [null, $message];
        }

        return [null, null];
    }

    /**
     * Pods of the service with the last log lines of the engine container.
     *
     * @return list<array{name: string, phase: string, ready: bool, logs: ?string}>
     */
    public function logs(AiModelService $service, int $tail = 200): array
    {
        $region = $service->region;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return [];
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $pods = $k8s->listResources('v1', 'Pod', $service->namespace(), [AiModelService::LABEL => $service->id])['items'] ?? [];
        $out = [];

        foreach (array_slice($pods, 0, 5) as $pod) {
            $name = (string) ($pod['metadata']['name'] ?? '');
            $ready = collect($pod['status']['conditions'] ?? [])->contains(fn ($c) => ($c['type'] ?? '') === 'Ready' && ($c['status'] ?? '') === 'True');
            $out[] = [
                'name' => $name,
                'phase' => (string) ($pod['status']['phase'] ?? 'Unknown'),
                'ready' => $ready,
                'logs' => $k8s->getPodLogs($name, $service->namespace(), 'model', max(10, min(2000, $tail))),
            ];
        }

        return $out;
    }

    /**
     * Playground call: through the Kubernetes API server to the service's cluster-local host on the
     * region Traefik's private entry point (KubernetesClient::knativePrivateRequest) — the route its
     * bound projects take, which wakes a service scaled to zero.
     *
     * @return array{status: int, duration_ms: int, content_type: string, body: mixed}
     */
    public function invoke(AiModelService $service, string $method, string $path, mixed $body): array
    {
        $region = $service->region;

        if ($region?->is_demo) {
            return ['status' => 200, 'duration_ms' => 1, 'content_type' => 'application/json', 'body' => self::demoAnswer($service, $path)];
        }

        if (! $region || ! $region->kubeconfig) {
            throw new \RuntimeException(__('ai.models.region_unavailable', ['region' => $region?->name ?? '?']));
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $service->namespace();
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
        if (! self::latestReadyRevision($k8s, $namespace, $service->service_name)) {
            throw new \RuntimeException(__('ai.models.not_ready'));
        }

        $payload = is_string($body) ? $body : json_encode($body ?? new \stdClass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $started = microtime(true);

        $response = $k8s->knativePrivateRequest(
            $method,
            $servingNs,
            $namespace,
            $service->service_name,
            ltrim($path, '/'),
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/plain, */*',
            ],
            $method === 'GET' ? null : $payload,
            (int) config('ai.models.invoke_timeout', 120),
            InternalNetwork::systemToken((string) $region->id),
        );

        $contentType = (string) ($response->header('Content-Type') ?: 'text/plain');
        $raw = mb_strcut($response->body(), 0, (int) config('ai.models.invoke_max_bytes', 4 * 1024 * 1024));
        $decoded = str_contains($contentType, 'json') ? json_decode($raw, true) : null;

        return [
            'status' => $response->status(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'content_type' => $contentType,
            'body' => $decoded ?? $raw,
        ];
    }

    /** May the playground call this path on the service's engine? */
    public static function allowedPath(AiModelService $service, string $path): bool
    {
        if (! str_starts_with($path, '/') || str_contains($path, '..') || preg_match('/[\s#?]/', $path)) {
            return false;
        }

        $allowed = EngineCatalog::invokePaths($service->engine);

        return in_array('*', $allowed, true) || in_array($path, $allowed, true);
    }

    private static function latestReadyRevision(KubernetesClient $k8s, string $namespace, string $name): ?string
    {
        $ksvc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $name, $namespace);

        return $ksvc['status']['latestReadyRevisionName'] ?? null;
    }

    private function fail(AiModelService $service, string $message): void
    {
        $service->update(['status' => AiModelService::STATUS_FAILED, 'status_message' => mb_substr($message, 0, 2000)]);
    }

    /** Answers of a demo region's simulated service. */
    private static function demoAnswer(AiModelService $service, string $path): array
    {
        return match (true) {
            str_contains($path, 'embeddings') => ['object' => 'list', 'model' => $service->served_name, 'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.12, -0.03, 0.44]]]],
            str_contains($path, 'chat') => ['object' => 'chat.completion', 'model' => $service->served_name, 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello from a demo model.'], 'finish_reason' => 'stop']]],
            default => ['model' => $service->served_name, 'task' => $service->model?->task, 'outputs' => [['label' => 'POSITIVE', 'score' => 0.98]]],
        };
    }

    /** Services waiting for a snapshot that is ready now: deploy them. */
    public function deployWaitingFor(AiModelSnapshot $snapshot): void
    {
        $waiting = AiModelService::where('ai_model_id', $snapshot->ai_model_id)
            ->where('knative_region_id', $snapshot->knative_region_id)
            ->where('status', AiModelService::STATUS_WAITING_SNAPSHOT)
            ->get();

        foreach ($waiting as $service) {
            if ($snapshot->isReady()) {
                DeployModelServiceJob::dispatch($service->id);
            } else {
                $this->fail($service, __('ai.models.snapshot_failed', ['message' => (string) $snapshot->message]));
            }
        }
    }

    /** Regions where a service can be deployed: active, real or demo. */
    public static function regionUsable(?KnativeRegion $region): bool
    {
        return $region !== null && ($region->is_demo || ($region->is_active && $region->kubeconfig));
    }
}
