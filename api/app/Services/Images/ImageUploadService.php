<?php

namespace App\Services\Images;

use App\Jobs\Images\CheckImageUploadJob;
use App\Models\AuditEvent;
use App\Models\ImageUpload;
use App\Models\KnativeRegion;
use App\Models\UploadedImage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\KubernetesClient;
use App\Services\Residency\RegionNotAllowedException;
use App\Services\Residency\RegionPolicy;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chunked upload of an image file into a region's registry (docs/images.md).
 *
 *   start()    checks (internal registry ready, data residency, quota), the upload row, the
 *              receiver Job + Service in the region (ImageUploadManifests)
 *   chunk()    one chunk, forwarded at once through the Kubernetes API server's service proxy to
 *              the receiver, which writes it at its offset — nothing is stored on the API's disk
 *              and the region needs no route to RunR or the internet; uploaded_bytes follows the
 *              receiver's contiguous prefix, so a client resumes after it
 *   complete() the receiver verifies size and SHA-256 and pushes (docker save / OCI layout tars,
 *              multi-platform indexes, .wasm modules); CheckImageUploadJob follows it
 *   refresh()  the receiver's state → the upload's status; a finished push becomes the
 *              UploadedImage (replacing the one of the same name and region), is audited, queued
 *              for the supply-chain pipeline (container images), and the receiver goes away
 *   abort() / expire()
 */
class ImageUploadService
{
    public function __construct(
        private ImageUploadManifests $manifests,
        private ImageQuota $quota,
        private UploadedImages $images,
        private RegionPolicy $residency,
    ) {}

    /**
     * @param  array{repository: string, tag: string, filename: string, size: int, sha256?: ?string, format?: ?string}  $data
     *
     * @throws ImageUploadException
     * @throws RegionNotAllowedException
     */
    public function start(KnativeRegion $region, array $data, ?string $userId): ImageUpload
    {
        $owner = ImageOwner::currentKey();

        if (! $region->is_demo) {
            if (! $region->kubeconfig || $region->registry_mode !== 'internal' || ! $region->has_registry) {
                throw new ImageUploadException(__('images.registry_unavailable', ['region' => $region->name]), 422, 'registry_unavailable');
            }
        }

        $this->residency->authorize($region, RegionPolicy::IMAGE_UPLOAD);

        $open = ImageUpload::query()->where('owner_key', $owner)->where('knative_region_id', $region->id)
            ->where('repository', $data['repository'])->where('tag', $data['tag'])
            ->whereIn('status', ImageUpload::OPEN)->first();
        if ($open) {
            throw new ImageUploadException(__('images.upload_open', ['name' => "{$data['repository']}:{$data['tag']}"]), 409, 'upload_open', ['upload_id' => $open->id]);
        }

        $replacing = UploadedImage::query()->where('owner_key', $owner)->where('knative_region_id', $region->id)
            ->where('repository', $data['repository'])->where('tag', $data['tag'])->first();
        if ($over = $this->quota->exceeded($owner, (int) $data['size'], $replacing)) {
            throw new ImageUploadException(__('images.quota_exceeded', [
                'limit' => self::gib($over['limit']), 'used' => self::gib($over['used']), 'requested' => self::gib($over['requested']),
            ]), 422, 'quota_exceeded', ['quota' => $over]);
        }

        $upload = ImageUpload::create([
            'owner_key' => $owner,
            'tenant_id' => ImageOwner::currentTenantId(),
            'knative_region_id' => $region->id,
            'repository' => $data['repository'],
            'tag' => $data['tag'],
            'filename' => mb_substr(basename(str_replace('\\', '/', (string) $data['filename'])), 0, 255),
            'format' => $data['format'] ?? 'auto',
            'size_bytes' => (int) $data['size'],
            'sha256' => ! empty($data['sha256']) ? strtolower((string) $data['sha256']) : null,
            'uploaded_bytes' => 0,
            'chunk_size' => (int) config('images.chunk_bytes', 8 * 1024 * 1024),
            'status' => ImageUpload::STATUS_PREPARING,
            'created_by' => $userId,
            'expires_at' => now()->addHours((int) config('images.upload_ttl_hours', 24)),
        ]);

        if ($region->is_demo || ! $region->kubeconfig) {
            $upload->update(['status' => ImageUpload::STATUS_READY]);

            return $upload;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = self::namespace($region);
            $name = ImageUploadManifests::receiverName($upload);

            if (! $k8s->createResource($this->manifests->job($upload, $region, $namespace), $namespace)
                || ! $k8s->applyResource($this->manifests->service($upload, $namespace), $namespace)) {
                throw new \RuntimeException(__('images.receiver_not_created'));
            }
            $upload->update(['receiver_name' => $name]);
        } catch (\Throwable $e) {
            $upload->update(['status' => ImageUpload::STATUS_FAILED, 'message' => $e->getMessage()]);
        }

        return $upload->fresh();
    }

    /** The receiver's state → the upload's status (receiver start, verification, push). */
    public function refresh(ImageUpload $upload): ImageUpload
    {
        if (! $upload->isOpen() || ! $upload->receiver_name) {
            return $upload;
        }

        $region = $upload->region();
        if (! $region || ! $region->kubeconfig) {
            return $upload;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $ns = self::namespace($region);

            if ($upload->status === ImageUpload::STATUS_PREPARING) {
                $this->refreshReadiness($upload, $k8s, $ns);

                return $upload->fresh();
            }
            if (! $upload->isFinishing()) {
                return $upload; // ready: chunks report their own progress
            }

            $response = $k8s->serviceProxyRequest('GET', $ns, $upload->receiver_name, ImageUploadManifests::PORT, 'status', ['Accept' => 'application/json']);
            if (! $response->successful()) {
                if ($this->receiverGone($k8s, $ns, $upload)) {
                    $this->failed($upload, __('images.receiver_stopped'));
                }

                return $upload->fresh();
            }

            $this->apply($upload, (array) $response->json());
        } catch (\Throwable $e) {
            Log::warning('Image upload status check failed', ['upload' => $upload->id, 'error' => $e->getMessage()]);
        }

        return $upload->fresh();
    }

    /**
     * Forward one chunk to the receiver.
     *
     * @return array{uploaded_bytes: int, size_bytes: int}
     *
     * @throws ImageUploadException
     */
    public function chunk(ImageUpload $upload, int $offset, string $bytes): array
    {
        $length = strlen($bytes);
        if ($offset < 0 || $length === 0 || $length > $upload->chunk_size || $offset + $length > $upload->size_bytes) {
            throw new ImageUploadException(__('images.bad_chunk', ['offset' => $offset, 'size' => $upload->size_bytes, 'chunk' => $upload->chunk_size]), 422, 'bad_chunk');
        }

        $region = $upload->region();
        if ($region && ! $region->is_demo && $region->kubeconfig) {
            $k8s = new KubernetesClient($region->kubeconfig);
            $response = $k8s->serviceProxySend(
                'PUT',
                self::namespace($region),
                (string) $upload->receiver_name,
                ImageUploadManifests::PORT,
                'upload?offset='.$offset,
                ['Content-Type' => 'application/octet-stream', 'Accept' => 'application/json'],
                $bytes,
                120,
            );

            if (! $response->successful()) {
                $message = $response->json('error') ?? mb_substr($response->body(), 0, 300);
                throw new ImageUploadException(__('images.chunk_failed', ['status' => $response->status(), 'message' => $message]), $response->status() === 409 ? 409 : 502, 'receiver_error');
            }
            $received = (int) $response->json('received', 0);
        } else {
            // demo region: no receiver, the contiguous prefix is tracked here
            $received = $offset <= $upload->uploaded_bytes ? max($upload->uploaded_bytes, $offset + $length) : $upload->uploaded_bytes;
        }

        // chunks may be answered out of order: never move uploaded_bytes back
        ImageUpload::query()->whereKey($upload->id)->where('uploaded_bytes', '<', $received)->update(['uploaded_bytes' => $received]);

        return ['uploaded_bytes' => (int) ImageUpload::query()->whereKey($upload->id)->value('uploaded_bytes'), 'size_bytes' => $upload->size_bytes];
    }

    /**
     * All bytes are there: the receiver verifies and pushes (asynchronously, CheckImageUploadJob).
     *
     * @throws ImageUploadException
     */
    public function complete(ImageUpload $upload): ImageUpload
    {
        if ($upload->uploaded_bytes < $upload->size_bytes) {
            throw new ImageUploadException(__('images.incomplete', ['received' => $upload->uploaded_bytes, 'size' => $upload->size_bytes]), 422, 'upload_incomplete', ['uploaded_bytes' => $upload->uploaded_bytes]);
        }

        $region = $upload->region();
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return $this->finishDemo($upload);
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $response = $k8s->serviceProxySend('POST', self::namespace($region), (string) $upload->receiver_name, ImageUploadManifests::PORT, 'complete',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'], '{}', 60);

        if ($response->status() === 409 && $response->json('received') !== null) {
            $upload->update(['uploaded_bytes' => (int) $response->json('received')]);
            throw new ImageUploadException(__('images.incomplete', ['received' => (int) $response->json('received'), 'size' => $upload->size_bytes]), 422, 'upload_incomplete', ['uploaded_bytes' => (int) $response->json('received')]);
        }
        if (! $response->successful()) {
            throw new ImageUploadException(__('images.receiver_error', ['message' => $response->json('error') ?? mb_substr($response->body(), 0, 300)]), 502, 'receiver_error');
        }

        $upload->update(['status' => ImageUpload::STATUS_VERIFYING, 'message' => null]);
        CheckImageUploadJob::start($upload);

        return $upload->fresh();
    }

    public function abort(ImageUpload $upload): void
    {
        $this->removeReceiver($upload);
        $upload->update(['status' => ImageUpload::STATUS_ABORTED]);
    }

    /** Uploads past their TTL: receiver removed, upload aborted (hourly). */
    public function expire(): int
    {
        $expired = ImageUpload::query()->whereIn('status', ImageUpload::OPEN)->where('expires_at', '<', now())->get();
        foreach ($expired as $upload) {
            $this->abort($upload);
            $upload->update(['message' => __('images.expired')]);
        }

        return $expired->count();
    }

    // ── Receiver state ──────────────────────────────────────────────────────

    private function refreshReadiness(ImageUpload $upload, KubernetesClient $k8s, string $ns): void
    {
        $pods = $k8s->listResources('v1', 'Pod', $ns, ['runr.sh/image-upload' => strtolower($upload->id)])['items'] ?? [];
        $pod = $pods[0] ?? null;
        if (! $pod) {
            return;
        }

        $ready = collect($pod['status']['conditions'] ?? [])->contains(fn ($c) => ($c['type'] ?? '') === 'Ready' && ($c['status'] ?? '') === 'True');
        if ($ready) {
            $upload->update(['status' => ImageUpload::STATUS_READY]);

            return;
        }

        $waiting = $pod['status']['containerStatuses'][0]['state']['waiting'] ?? null;
        if (in_array($pod['status']['phase'] ?? '', ['Failed', 'Succeeded'], true)) {
            $this->failed($upload, __('images.receiver_stopped'));
        } elseif (in_array($waiting['reason'] ?? null, ['ErrImagePull', 'ImagePullBackOff', 'InvalidImageName'], true)) {
            $this->failed($upload, __('images.receiver_image', ['image' => (string) config('images.upload_image'), 'reason' => $waiting['message'] ?? $waiting['reason']]));
        }
    }

    /** @param array{state?: string, received?: int, error?: string, sha256?: string, result?: array} $status */
    private function apply(ImageUpload $upload, array $status): void
    {
        switch ($status['state'] ?? null) {
            case 'receiving':
                // a SHA-256 mismatch reopens the upload with nothing received
                $upload->update([
                    'status' => ImageUpload::STATUS_READY,
                    'uploaded_bytes' => (int) ($status['received'] ?? 0),
                    'message' => $status['error'] ?? null,
                ]);
                break;
            case 'verifying':
                $upload->update(['status' => ImageUpload::STATUS_VERIFYING]);
                break;
            case 'pushing':
                $upload->update(['status' => ImageUpload::STATUS_PUSHING]);
                break;
            case 'pushed':
                $this->finalize($upload, (array) ($status['result'] ?? []), $status['sha256'] ?? null);
                break;
            case 'failed':
                $this->failed($upload, (string) ($status['error'] ?? __('images.push_failed')));
                break;
        }
    }

    /** The push finished: the uploaded image record (replacing the one of the same name). */
    public function finalize(ImageUpload $upload, array $result, ?string $sha256 = null): UploadedImage
    {
        if (! preg_match(ImageName::DIGEST, (string) ($result['digest'] ?? ''))) {
            $this->failed($upload, __('images.push_failed'));
            throw new ImageUploadException(__('images.push_failed'), 502, 'receiver_error');
        }

        $region = $upload->region();
        $repository = ImageName::registryRepository($upload->owner_key, $upload->repository);
        $previous = null;

        $image = DB::connection((new UploadedImage)->getConnectionName())->transaction(function () use ($upload, $result, $sha256, $repository, &$previous) {
            $image = UploadedImage::query()->firstOrNew([
                'owner_key' => $upload->owner_key,
                'knative_region_id' => $upload->knative_region_id,
                'repository' => $upload->repository,
                'tag' => $upload->tag,
            ]);
            $previous = $image->exists ? $image->digest : null;

            $image->fill([
                'tenant_id' => $upload->tenant_id,
                'registry_repository' => $repository,
                'digest' => $result['digest'],
                'media_type' => mb_substr((string) ($result['media_type'] ?? ''), 0, 128),
                'config_media_type' => isset($result['config_media_type']) ? mb_substr((string) $result['config_media_type'], 0, 128) : null,
                'kind' => in_array($result['kind'] ?? null, UploadedImage::KINDS, true) ? $result['kind'] : UploadedImage::KIND_IMAGE,
                'source_format' => in_array($result['format'] ?? null, ['docker', 'oci', 'wasm'], true) ? $result['format'] : 'oci',
                'size_bytes' => max(0, (int) ($result['size_bytes'] ?? 0)),
                'archive_bytes' => $upload->size_bytes,
                'archive_sha256' => $sha256 ?? ($result['archive_sha256'] ?? null),
                'platforms' => array_values(array_filter((array) ($result['platforms'] ?? []), 'is_string')),
                'layers' => max(0, (int) ($result['layers'] ?? 0)),
                'source_filename' => $upload->filename,
                'uploaded_by' => $upload->created_by,
                'note' => isset($result['note']) ? mb_substr((string) $result['note'], 0, 1000) : null,
            ])->save();

            $upload->update([
                'status' => ImageUpload::STATUS_COMPLETED,
                'uploaded_image_id' => $image->id,
                'completed_at' => now(),
                'message' => $image->note,
            ]);

            return $image;
        });

        $this->removeReceiver($upload);

        // The image this upload replaced: its manifest goes unless something still uses it
        if ($previous !== null && $previous !== $image->digest) {
            $this->images->removeManifest($region, $repository, $previous);
        }

        $this->audit('image.upload.pushed', $upload, AuditEvent::RESULT_SUCCESS, [
            'uploaded_image_id' => $image->id, 'name' => $image->name(), 'digest' => $image->digest,
            'kind' => $image->kind, 'format' => $image->source_format, 'size_bytes' => $image->size_bytes, 'replaced_digest' => $previous,
        ]);

        if ($image->kind === UploadedImage::KIND_IMAGE) {
            try {
                app(SupplyChainPipeline::class)->queueForUploadedImage($image);
            } catch (\Throwable $e) {
                Log::warning('Uploaded image could not be queued for the supply chain', ['image' => $image->id, 'error' => $e->getMessage()]);
            }
        }

        return $image;
    }

    private function finishDemo(ImageUpload $upload): ImageUpload
    {
        $wasm = $upload->format === 'wasm' || str_ends_with(strtolower($upload->filename), '.wasm');
        $this->finalize($upload, [
            'digest' => 'sha256:'.hash('sha256', $upload->id),
            'media_type' => 'application/vnd.oci.image.manifest.v1+json',
            'kind' => $wasm ? UploadedImage::KIND_WASM : UploadedImage::KIND_IMAGE,
            'format' => $wasm ? 'wasm' : ($upload->format === 'docker' ? 'docker' : 'oci'),
            'size_bytes' => $upload->size_bytes,
            'platforms' => [$wasm ? 'wasip1/wasm' : 'linux/amd64'],
            'layers' => 1,
        ], $upload->sha256);

        return $upload->fresh();
    }

    private function failed(ImageUpload $upload, string $message): void
    {
        $upload->update(['status' => ImageUpload::STATUS_FAILED, 'message' => mb_substr($message, 0, 2000)]);
        $this->removeReceiver($upload);
        $this->audit('image.upload.failed', $upload, AuditEvent::RESULT_FAILURE, ['name' => "{$upload->repository}:{$upload->tag}", 'error' => mb_substr($message, 0, 500)]);
    }

    private function receiverGone(KubernetesClient $k8s, string $ns, ImageUpload $upload): bool
    {
        $job = $k8s->getResourceStatus('batch/v1', 'Job', (string) $upload->receiver_name, $ns);

        return $job === null || ($job['status']['failed'] ?? 0) >= 1;
    }

    private function removeReceiver(ImageUpload $upload): void
    {
        $region = $upload->region();
        if (! $upload->receiver_name || ! $region || $region->is_demo || ! $region->kubeconfig) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $ns = self::namespace($region);
            $k8s->deleteResource('batch/v1', 'Job', $upload->receiver_name, $ns, 'Background');
            $k8s->deleteResource('v1', 'Service', $upload->receiver_name, $ns);
        } catch (\Throwable $e) {
            Log::warning('Image upload receiver cleanup failed', ['upload' => $upload->id, 'error' => $e->getMessage()]);
        }
    }

    /** Results of the region side, recorded where they happen (AuditCatalog::EXPLICIT_ACTIONS). */
    private function audit(string $action, ImageUpload $upload, string $result, array $metadata): void
    {
        $actor = null;
        if ($upload->created_by) {
            try {
                $actor = User::find($upload->created_by);
            } catch (\Throwable) {
                $actor = null;
            }
        }

        app(AuditLogger::class)->record($action, array_filter([
            'actor' => auth()->user() ?? $actor ?? 'system',
            'tenant_id' => $upload->tenant_id,
            'result' => $result,
            'target' => ['type' => 'image_upload', 'id' => $upload->id, 'name' => "{$upload->repository}:{$upload->tag}"],
            'metadata' => $metadata + ['knative_region_id' => $upload->knative_region_id, 'upload_id' => $upload->id],
        ], fn ($v) => $v !== null));
    }

    public static function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?: $region->name;
    }

    private static function gib(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / ImageQuota::GIB, 2, '.', ''), '0'), '.').' GiB';
    }
}
