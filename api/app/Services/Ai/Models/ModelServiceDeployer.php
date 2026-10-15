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
    public function __construct(
        private ModelServiceManifests $manifests,
        private ModelStorage $storage,
        private HubToken $token,
        private SnapshotService $snapshots,
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
     * Readiness of the Knative Service: [ready (true/false/null = still starting), message].
     *
     * @return array{0: ?bool, 1: ?string}
     */
    public function readiness(AiModelService $service): array
    {
        $region = $service->region;
        if (! $region || ! $region->kubeconfig) {
            return [false, __('ai.models.region_unavailable', ['region' => $region?->name ?? '?'])];
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $ksvc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $service->service_name, $service->namespace());
        if (! $ksvc) {
            return [null, null];
        }

        return self::readinessOf($ksvc);
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
     * Playground call: through the Kubernetes API server to the Knative activator (wakes a service
     * scaled to zero), with the revision headers the activator routes by.
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
        $revision = self::latestReadyRevision($k8s, $namespace, $service->service_name);

        if (! $revision) {
            throw new \RuntimeException(__('ai.models.not_ready'));
        }

        $payload = is_string($body) ? $body : json_encode($body ?? new \stdClass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $started = microtime(true);

        $response = $k8s->serviceProxySend(
            $method,
            $servingNs,
            'activator-service',
            80,
            ltrim($path, '/'),
            [
                'Host' => $service->service_name.'.'.$namespace.'.svc.cluster.local',
                'Knative-Serving-Namespace' => $namespace,
                'Knative-Serving-Revision' => $revision,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/plain, */*',
            ],
            $method === 'GET' ? null : $payload,
            (int) config('ai.models.invoke_timeout', 120),
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
