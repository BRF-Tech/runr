<?php

namespace App\Services\Ai\Models;

use App\Jobs\Ai\CheckModelSnapshotJob;
use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Ai\Hub\HubToken;
use App\Services\KubernetesClient;
use App\Support\DeployGate;
use Illuminate\Support\Facades\Log;

/**
 * Offline copies of models in a region's model volume ("closed circuit", docs/huggingface.md):
 *
 *  - hub: a mirror Job runs huggingface_hub.snapshot_download of the pinned commit once;
 *  - pvc: a Job copies a folder of the organization's import area (imports/<tenant>/…);
 *  - oci: a Job pulls an OCI artifact with ORAS (e.g. pushed into the region registry);
 *  - upload: files arrive through UploadService (no Job).
 *
 * The Job writes .runr-snapshot.json and prints `RUNR_SNAPSHOT {json}`; CheckModelSnapshotJob
 * reads the Job status and that line (size, file count) and marks the snapshot ready / failed.
 */
class SnapshotService
{
    public function __construct(
        private ModelServiceManifests $manifests,
        private ModelStorage $storage,
        private HubToken $token,
    ) {}

    /** The model's snapshot in the region (started when there is none, restarted when failed). */
    public function ensureFor(AiModel $model, KnativeRegion $region): AiModelSnapshot
    {
        $revision = $model->isHub() ? $model->pinnedRevision() : 'local';

        $snapshot = AiModelSnapshot::firstOrCreate(
            ['ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'revision' => $revision],
            [
                'source' => $model->source,
                'path' => $this->storage->snapshotSubPath($model, $revision),
                'status' => AiModelSnapshot::STATUS_PENDING,
            ],
        );

        if ($snapshot->wasRecentlyCreated || $snapshot->status === AiModelSnapshot::STATUS_FAILED) {
            $this->start($snapshot);
        }

        return $snapshot->fresh();
    }

    public function start(AiModelSnapshot $snapshot): void
    {
        $snapshot->loadMissing('model', 'region');
        $model = $snapshot->model;
        $region = $snapshot->region;

        if ($snapshot->source === 'upload') {
            // Files come through an upload (UploadService); nothing to run
            $snapshot->update(['status' => AiModelSnapshot::STATUS_PENDING, 'message' => __('ai.models.snapshot_needs_upload')]);

            return;
        }

        if ($blocked = DeployGate::blockedReason()) {
            $this->fail($snapshot, $blocked);

            return;
        }

        if (! $region || $region->is_demo) {
            $snapshot->update([
                'status' => AiModelSnapshot::STATUS_READY,
                'message' => null,
                'size_bytes' => $model?->size_bytes,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            return;
        }

        if (! $region->kubeconfig) {
            $this->fail($snapshot, __('ai.models.region_unavailable', ['region' => $region->name]));

            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?: $region->name;

            app(GpuInstallService::class)->enableKnativeFeatures($k8s, $region);
            $this->storage->ensure($region, $k8s, $namespace);

            $job = match ($snapshot->source) {
                'hub' => $this->manifests->mirrorJob($snapshot, $model, $region, $namespace, $this->token->syncSecret($region, $k8s, $namespace)),
                'pvc' => $this->manifests->importJob($snapshot, $model, $region, $namespace),
                'oci' => $this->manifests->importJob($snapshot, $model, $region, $namespace, $this->registrySecret($region, $k8s, $namespace, (string) $model->source_ref)),
                default => throw new \RuntimeException("Unknown snapshot source {$snapshot->source}"),
            };

            // A previous (failed) Job of the same snapshot has the same name
            $k8s->deleteResource('batch/v1', 'Job', $job['metadata']['name'], $namespace, 'Background');

            if (! $k8s->createResource($job, $namespace)) {
                throw new \RuntimeException(__('ai.models.apply_failed', ['kind' => 'Job']));
            }

            $snapshot->update([
                'status' => AiModelSnapshot::STATUS_RUNNING,
                'message' => null,
                'job_name' => $job['metadata']['name'],
                'started_at' => now(),
                'completed_at' => null,
            ]);

            CheckModelSnapshotJob::dispatch($snapshot->id, 1, CheckModelSnapshotJob::startToken($snapshot))->delay(now()->addSeconds(15));
        } catch (\Throwable $e) {
            Log::error('Model snapshot start failed', ['snapshot' => $snapshot->id, 'error' => $e->getMessage()]);
            $this->fail($snapshot, $e->getMessage());
        }
    }

    /**
     * Job state → snapshot status. Returns true when the snapshot reached a final status.
     */
    public function check(AiModelSnapshot $snapshot): bool
    {
        $region = $snapshot->region;
        if (! $region || ! $region->kubeconfig || ! $snapshot->job_name) {
            return true;
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $region->project_namespace ?: $region->name;
        $job = $k8s->getResourceStatus('batch/v1', 'Job', $snapshot->job_name, $namespace);

        if (! $job) {
            $this->fail($snapshot, __('ai.models.job_missing', ['name' => $snapshot->job_name]));

            return true;
        }

        $state = self::jobState($job);
        if ($state === null) {
            $this->reflectVolume($snapshot, $k8s, $namespace);

            return false;
        }

        $logs = $this->jobLogs($k8s, $namespace, $snapshot->job_name);
        $result = self::parseResult($logs);

        if ($state === 'complete') {
            $snapshot->update([
                'status' => AiModelSnapshot::STATUS_READY,
                'message' => null,
                'size_bytes' => $result['size_bytes'] ?? $snapshot->model?->size_bytes,
                'files_count' => $result['files_count'] ?? (isset($result['files']) ? count((array) $result['files']) : null),
                'completed_at' => now(),
            ]);

            if (isset($result['revision']) && $snapshot->model && $snapshot->model->isHub() && ! $snapshot->model->revision_sha) {
                $snapshot->model->update(['revision_sha' => (string) $result['revision']]);
            }
        } else {
            $this->fail($snapshot, $result['error'] ?? self::lastLines($logs) ?? __('ai.models.job_failed'));
        }

        return true;
    }

    /**
     * A Job that has not finished may be waiting for the model volume: no pod of it starts while
     * the claim is not bound (e.g. a ReadWriteMany claim the local-path provisioner can not
     * provision). Then the snapshot is pending with the reason — not running without a word —
     * and so are the services that wait for it; once the claim is bound it is running again.
     */
    private function reflectVolume(AiModelSnapshot $snapshot, KubernetesClient $k8s, string $namespace): void
    {
        try {
            $waiting = $this->storage->notBoundMessage($k8s, $namespace);
        } catch (\Throwable) {
            return; // unknown: the status stays as it is
        }

        $status = $waiting !== null ? AiModelSnapshot::STATUS_PENDING : AiModelSnapshot::STATUS_RUNNING;
        $message = $waiting !== null ? mb_substr($waiting, 0, 2000) : null;
        if ($snapshot->status === $status && $snapshot->message === $message) {
            return;
        }

        $snapshot->update(['status' => $status, 'message' => $message]);

        AiModelService::where('ai_model_id', $snapshot->ai_model_id)
            ->where('knative_region_id', $snapshot->knative_region_id)
            ->where('status', AiModelService::STATUS_WAITING_SNAPSHOT)
            ->update(['status_message' => mb_substr(trim(__('ai.models.waiting_snapshot', ['status' => $status]).' '.$message), 0, 2000)]);
    }

    /** Remove a snapshot's files (cleanup Job) and its row. */
    public function delete(AiModelSnapshot $snapshot): void
    {
        $region = $snapshot->region;

        if ($region && ! $region->is_demo && $region->kubeconfig) {
            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $namespace = $region->project_namespace ?: $region->name;
                if ($snapshot->job_name) {
                    $k8s->deleteResource('batch/v1', 'Job', $snapshot->job_name, $namespace, 'Background');
                }
                $cleanup = $this->manifests->cleanupJob($snapshot, $region, $namespace);
                $k8s->deleteResource('batch/v1', 'Job', $cleanup['metadata']['name'], $namespace, 'Background');
                $k8s->createResource($cleanup, $namespace);
            } catch (\Throwable $e) {
                Log::warning('Model snapshot cleanup failed', ['snapshot' => $snapshot->id, 'error' => $e->getMessage()]);
            }
        }

        $snapshot->delete();
    }

    public function logs(AiModelSnapshot $snapshot): ?string
    {
        $region = $snapshot->region;
        if (! $region || ! $region->kubeconfig || ! $snapshot->job_name) {
            return null;
        }

        $k8s = new KubernetesClient($region->kubeconfig);

        return $this->jobLogs($k8s, $region->project_namespace ?: $region->name, $snapshot->job_name);
    }

    /** 'complete' | 'failed' | null (running). */
    public static function jobState(array $job): ?string
    {
        foreach ($job['status']['conditions'] ?? [] as $condition) {
            if (($condition['status'] ?? '') !== 'True') {
                continue;
            }
            if (($condition['type'] ?? '') === 'Complete') {
                return 'complete';
            }
            if (($condition['type'] ?? '') === 'Failed') {
                return 'failed';
            }
        }

        if ((int) ($job['status']['succeeded'] ?? 0) > 0) {
            return 'complete';
        }

        return null;
    }

    /**
     * The `RUNR_SNAPSHOT {json}` (or `RUNR_ERROR message`) line of a Job's log.
     *
     * @return array{size_bytes?: int, files_count?: int, files?: array, revision?: string, error?: string}
     */
    public static function parseResult(?string $logs): array
    {
        $result = [];
        foreach (preg_split('/\R/', (string) $logs) as $line) {
            if (str_starts_with($line, 'RUNR_SNAPSHOT ')) {
                $json = json_decode(substr($line, 14), true);
                if (is_array($json)) {
                    $result = $json;
                }
            } elseif (str_starts_with($line, 'RUNR_ERROR ')) {
                $result['error'] = trim(substr($line, 11));
            }
        }

        return $result;
    }

    private static function lastLines(?string $logs, int $lines = 5): ?string
    {
        $all = array_values(array_filter(preg_split('/\R/', trim((string) $logs)) ?: []));

        return $all ? implode("\n", array_slice($all, -$lines)) : null;
    }

    private function jobLogs(KubernetesClient $k8s, string $namespace, string $jobName): ?string
    {
        $pods = $k8s->listResources('v1', 'Pod', $namespace, ['job-name' => $jobName])['items'] ?? [];
        if ($pods === []) {
            return null;
        }

        usort($pods, fn ($a, $b) => strcmp((string) ($b['metadata']['creationTimestamp'] ?? ''), (string) ($a['metadata']['creationTimestamp'] ?? '')));
        $name = (string) ($pods[0]['metadata']['name'] ?? '');
        $container = $pods[0]['spec']['containers'][0]['name'] ?? null;

        return $k8s->getPodLogs($name, $namespace, $container, 200);
    }

    /**
     * Docker config Secret for an OCI import from the region's own registry (its credentials);
     * other registries are pulled anonymously.
     */
    private function registrySecret(KnativeRegion $region, KubernetesClient $k8s, string $namespace, string $reference): ?string
    {
        $address = (string) $region->registry_address;
        $host = preg_replace('#^https?://#', '', rtrim($address, '/'));
        if ($host === '' || ! str_starts_with($reference, $host.'/') || ! $region->registry_username) {
            return null;
        }

        $name = Project::slugFor('runr-models-registry');
        $auth = base64_encode($region->registry_username.':'.(string) $region->registry_password);
        $k8s->applyResource([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            'type' => 'kubernetes.io/dockerconfigjson',
            'stringData' => ['.dockerconfigjson' => json_encode(['auths' => [$host => ['auth' => $auth]]], JSON_UNESCAPED_SLASHES)],
        ], $namespace);

        return $name;
    }

    private function fail(AiModelSnapshot $snapshot, string $message): void
    {
        $snapshot->update(['status' => AiModelSnapshot::STATUS_FAILED, 'message' => mb_substr($message, 0, 2000), 'completed_at' => now()]);
        app(ModelServiceDeployer::class)->deployWaitingFor($snapshot->fresh());
    }
}
