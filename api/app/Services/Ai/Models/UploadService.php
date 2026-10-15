<?php

namespace App\Services\Ai\Models;

use App\Models\AiModel;
use App\Models\AiModelSnapshot;
use App\Models\AiModelUpload;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

/**
 * Chunked upload of model files into a region (air-gapped regions, docs/huggingface.md#air-gapped-regions).
 *
 * Starting an upload creates the snapshot (source upload) and a receiver pod + Service in the
 * region (hf-serve `receive`, the snapshot folder mounted). Every chunk the browser or the CLI
 * sends (PUT …/chunk?path=&offset=, raw bytes, at most chunk_size) is forwarded at once through
 * the Kubernetes API server to the receiver, which writes it at its offset — nothing is stored on
 * the RunR API's disk, and the region needs no route to RunR or the internet. Completing the
 * upload has the receiver verify every file (size, sha256 when given) and write
 * .runr-snapshot.json; the snapshot is ready and the receiver goes away.
 */
class UploadService
{
    public function __construct(
        private ModelServiceManifests $manifests,
        private ModelStorage $storage,
    ) {}

    /**
     * @param  list<array{path: string, size: int, sha256?: ?string}>  $files
     */
    public function start(AiModel $model, KnativeRegion $region, array $files, ?string $userId): AiModelUpload
    {
        $revision = 'local';
        $snapshot = AiModelSnapshot::updateOrCreate(
            ['ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'revision' => $revision],
            [
                'source' => 'upload',
                'path' => $this->storage->snapshotSubPath($model, $revision),
                'status' => AiModelSnapshot::STATUS_RUNNING,
                'message' => null,
                'started_at' => now(),
                'completed_at' => null,
            ],
        );

        $upload = AiModelUpload::create([
            'ai_model_id' => $model->id,
            'knative_region_id' => $region->id,
            'status' => AiModelUpload::STATUS_PREPARING,
            'files' => array_map(fn ($f) => [
                'path' => self::normalizePath((string) $f['path']),
                'size' => (int) $f['size'],
                'sha256' => isset($f['sha256']) && $f['sha256'] !== '' ? strtolower((string) $f['sha256']) : null,
                'uploaded_bytes' => 0,
            ], $files),
            'chunk_size' => (int) config('ai.models.upload_chunk_bytes', 8 * 1024 * 1024),
            'snapshot_id' => $snapshot->id,
            'created_by' => $userId,
            'expires_at' => now()->addHours((int) config('ai.models.upload_ttl_hours', 24)),
        ]);

        if ($region->is_demo || ! $region->kubeconfig) {
            $upload->update(['status' => AiModelUpload::STATUS_READY]);

            return $upload;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?: $region->name;

            $this->storage->ensure($region, $k8s, $namespace);
            $pod = $this->manifests->receiverPod($upload, $snapshot, $region, $namespace);
            $service = $this->manifests->receiverService($upload, $namespace);

            if (! $k8s->createResource($pod, $namespace) || ! $k8s->applyResource($service, $namespace)) {
                throw new \RuntimeException(__('ai.models.apply_failed', ['kind' => 'upload receiver']));
            }

            $upload->update(['receiver_name' => $pod['metadata']['name']]);
        } catch (\Throwable $e) {
            $upload->update(['status' => AiModelUpload::STATUS_FAILED, 'message' => $e->getMessage()]);
            $snapshot->update(['status' => AiModelSnapshot::STATUS_FAILED, 'message' => $e->getMessage()]);
        }

        return $upload->fresh();
    }

    /** The receiver answers: the upload accepts chunks. */
    public function refreshReadiness(AiModelUpload $upload): AiModelUpload
    {
        if ($upload->status !== AiModelUpload::STATUS_PREPARING || ! $upload->receiver_name) {
            return $upload;
        }

        $region = $upload->region;
        try {
            $k8s = new KubernetesClient((string) $region->kubeconfig);
            $pod = $k8s->getResourceStatus('v1', 'Pod', $upload->receiver_name, $region->project_namespace ?: $region->name);
        } catch (\Throwable) {
            return $upload;
        }

        $ready = collect($pod['status']['conditions'] ?? [])->contains(fn ($c) => ($c['type'] ?? '') === 'Ready' && ($c['status'] ?? '') === 'True');
        if ($ready) {
            $upload->update(['status' => AiModelUpload::STATUS_READY]);
        } elseif (in_array($pod['status']['phase'] ?? '', ['Failed', 'Succeeded'], true)) {
            $upload->update(['status' => AiModelUpload::STATUS_FAILED, 'message' => __('ai.uploads.receiver_stopped')]);
        }

        return $upload->fresh();
    }

    /**
     * Forward one chunk to the receiver.
     *
     * @return array{path: string, uploaded_bytes: int}
     *
     * @throws \InvalidArgumentException a chunk that does not fit the declared file
     * @throws \RuntimeException the receiver did not take it
     */
    public function chunk(AiModelUpload $upload, string $path, int $offset, string $bytes): array
    {
        $path = self::normalizePath($path);
        $entry = $upload->fileEntry($path);

        if ($entry === null) {
            throw new \InvalidArgumentException(__('ai.uploads.unknown_file', ['path' => $path]));
        }
        if ($offset < 0 || strlen($bytes) === 0 || strlen($bytes) > $upload->chunk_size || $offset + strlen($bytes) > (int) $entry['size']) {
            throw new \InvalidArgumentException(__('ai.uploads.bad_chunk', ['path' => $path]));
        }

        $region = $upload->region;
        if (! $region->is_demo && $region->kubeconfig) {
            $k8s = new KubernetesClient($region->kubeconfig);
            $response = $k8s->serviceProxySend(
                'PUT',
                $region->project_namespace ?: $region->name,
                (string) $upload->receiver_name,
                ModelServiceManifests::PORT,
                'files/'.self::encodePath($path).'?offset='.$offset,
                ['Content-Type' => 'application/octet-stream', 'Accept' => 'application/json'],
                $bytes,
                120,
            );

            if (! $response->successful()) {
                throw new \RuntimeException(__('ai.uploads.chunk_failed', ['status' => $response->status(), 'message' => mb_substr($response->body(), 0, 300)]));
            }
        }

        // Highest byte received per file (chunks may arrive in any order; the receiver verifies sizes)
        $files = $upload->files;
        foreach ($files as &$file) {
            if ($file['path'] === $path) {
                $file['uploaded_bytes'] = min((int) $file['size'], max((int) $file['uploaded_bytes'], $offset + strlen($bytes)));
                $uploaded = $file['uploaded_bytes'];
            }
        }
        unset($file);
        $upload->update(['files' => $files]);

        return ['path' => $path, 'uploaded_bytes' => (int) ($uploaded ?? 0)];
    }

    /** Verify the files on the receiver, finish the snapshot and remove the receiver. */
    public function complete(AiModelUpload $upload): AiModelSnapshot
    {
        $snapshot = AiModelSnapshot::findOrFail($upload->snapshot_id);
        $region = $upload->region;
        $upload->update(['status' => AiModelUpload::STATUS_COMPLETING]);

        $size = array_sum(array_map(fn ($f) => (int) $f['size'], $upload->files));
        $count = count($upload->files);

        if (! $region->is_demo && $region->kubeconfig) {
            $k8s = new KubernetesClient($region->kubeconfig);
            $response = $k8s->serviceProxySend(
                'POST',
                $region->project_namespace ?: $region->name,
                (string) $upload->receiver_name,
                ModelServiceManifests::PORT,
                'complete',
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                json_encode(['files' => array_map(fn ($f) => array_filter([
                    'path' => $f['path'],
                    'size' => (int) $f['size'],
                    'sha256' => $f['sha256'] ?? null,
                ], fn ($v) => $v !== null), $upload->files)], JSON_UNESCAPED_SLASHES),
                600,
            );

            if (! $response->successful() || ! ($response->json('ok') ?? false)) {
                $detail = $response->json('error') ?? $response->json('detail') ?? $response->json('message');
                $message = is_string($detail) ? $detail : (is_array($detail) ? json_encode($detail) : mb_substr($response->body(), 0, 500));
                $upload->update(['status' => AiModelUpload::STATUS_READY, 'message' => $message]);

                throw new \RuntimeException(__('ai.uploads.verify_failed', ['message' => $message]));
            }

            $size = (int) ($response->json('size_bytes') ?? $size);
            $this->removeReceiver($upload, $k8s);
        }

        $snapshot->update([
            'status' => AiModelSnapshot::STATUS_READY,
            'message' => null,
            'size_bytes' => $size,
            'files_count' => $count,
            'completed_at' => now(),
        ]);
        $upload->update(['status' => AiModelUpload::STATUS_COMPLETED, 'message' => null]);

        if (! $upload->model->size_bytes) {
            $upload->model->update(['size_bytes' => $size]);
        }

        app(ModelServiceDeployer::class)->deployWaitingFor($snapshot->fresh());

        return $snapshot->fresh();
    }

    public function abort(AiModelUpload $upload): void
    {
        $region = $upload->region;
        if ($region && ! $region->is_demo && $region->kubeconfig) {
            try {
                $this->removeReceiver($upload, new KubernetesClient($region->kubeconfig));
            } catch (\Throwable $e) {
                Log::warning('Upload receiver cleanup failed', ['upload' => $upload->id, 'error' => $e->getMessage()]);
            }
        }

        $upload->update(['status' => AiModelUpload::STATUS_ABORTED]);
        AiModelSnapshot::whereKey($upload->snapshot_id)
            ->where('status', '!=', AiModelSnapshot::STATUS_READY)
            ->update(['status' => AiModelSnapshot::STATUS_FAILED, 'message' => __('ai.uploads.aborted')]);
    }

    /** Uploads past their TTL: receivers removed, upload aborted (models:gc). */
    public function expire(): int
    {
        $expired = AiModelUpload::whereIn('status', [AiModelUpload::STATUS_PREPARING, AiModelUpload::STATUS_READY, AiModelUpload::STATUS_COMPLETING])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $upload) {
            $this->abort($upload);
        }

        return $expired->count();
    }

    private function removeReceiver(AiModelUpload $upload, KubernetesClient $k8s): void
    {
        if (! $upload->receiver_name) {
            return;
        }

        $namespace = $upload->region->project_namespace ?: $upload->region->name;
        $k8s->deleteResource('v1', 'Pod', $upload->receiver_name, $namespace);
        $k8s->deleteResource('v1', 'Service', $upload->receiver_name, $namespace);
    }

    /** Relative POSIX path without leading slash, "." or ".." segments; '' when invalid. */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = array_values(array_filter(explode('/', $path), fn ($p) => $p !== '' && $p !== '.'));

        if ($parts === [] || in_array('..', $parts, true) || str_contains($path, "\0")) {
            return '';
        }

        return implode('/', $parts);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
