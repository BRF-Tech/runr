<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\Ai\AiModelSnapshotResource;
use App\Http\Resources\Ai\AiModelUploadResource;
use App\Models\AiModel;
use App\Models\AiModelUpload;
use App\Models\KnativeRegion;
use App\Services\Ai\Models\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Chunked upload of model files into a region's model volume — for air-gapped regions
 * (docs/huggingface.md#air-gapped-regions). The files of one upload become the model's offline
 * snapshot in that region.
 *
 * @tags AI · Models
 */
class ModelUploadController extends Controller
{
    public function __construct(private UploadService $uploads) {}

    public function store(Request $request, AiModel $model): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $max = (int) config('ai.models.upload_max_files', 500);
        $data = $request->validate([
            'knative_region_id' => 'required|string|exists:knative_regions,id',
            'files' => "required|array|min:1|max:{$max}",
            'files.*.path' => 'required|string|max:400|distinct',
            'files.*.size' => 'required|integer|min:1|max:'.(512 * 1024 ** 3),
            'files.*.sha256' => 'nullable|string|regex:/^[A-Fa-f0-9]{64}$/',
        ]);

        if ($model->source !== 'upload') {
            return response()->json(['message' => __('ai.uploads.wrong_source')], 422);
        }

        foreach ($data['files'] as $i => $file) {
            if (UploadService::normalizePath((string) $file['path']) === '') {
                return response()->json(['message' => __('ai.uploads.bad_path'), 'errors' => ["files.{$i}.path" => [__('ai.uploads.bad_path')]]], 422);
            }
        }

        $region = KnativeRegion::findOrFail($data['knative_region_id']);
        $open = AiModelUpload::where('ai_model_id', $model->id)->where('knative_region_id', $region->id)
            ->whereIn('status', [AiModelUpload::STATUS_PREPARING, AiModelUpload::STATUS_READY, AiModelUpload::STATUS_COMPLETING])
            ->exists();
        if ($open) {
            return response()->json(['message' => __('ai.uploads.already_open')], 409);
        }

        $upload = $this->uploads->start($model, $region, $data['files'], (string) $request->user()?->getKey());

        return response()->json(new AiModelUploadResource($upload), 201);
    }

    public function show(AiModel $model, AiModelUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        return response()->json(new AiModelUploadResource($this->uploads->refreshReadiness($upload)));
    }

    /** One chunk: raw body (application/octet-stream), ?path=<file>&offset=<byte>. */
    public function chunk(Request $request, AiModel $model, AiModelUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $data = $request->validate([
            'path' => 'required|string|max:400',
            'offset' => 'required|integer|min:0',
        ]);

        $upload = $this->uploads->refreshReadiness($upload);
        if ($upload->status !== AiModelUpload::STATUS_READY) {
            return response()->json(['message' => __('ai.uploads.not_ready', ['status' => $upload->status])], 409);
        }

        try {
            $result = $this->uploads->chunk($upload, $data['path'], (int) $data['offset'], $request->getContent());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }

    public function complete(AiModel $model, AiModelUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $upload = $this->uploads->refreshReadiness($upload);
        if ($upload->status !== AiModelUpload::STATUS_READY) {
            return response()->json(['message' => __('ai.uploads.not_ready', ['status' => $upload->status])], 409);
        }

        $missing = array_values(array_filter($upload->files, fn ($f) => (int) $f['uploaded_bytes'] < (int) $f['size']));
        if ($missing !== []) {
            return response()->json([
                'message' => __('ai.uploads.incomplete', ['files' => implode(', ', array_slice(array_column($missing, 'path'), 0, 5))]),
                'missing' => array_column($missing, 'path'),
            ], 422);
        }

        try {
            $snapshot = $this->uploads->complete($upload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'upload' => new AiModelUploadResource($upload->fresh()),
            'snapshot' => new AiModelSnapshotResource($snapshot->load('region')),
        ]);
    }

    public function destroy(AiModel $model, AiModelUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $this->uploads->abort($upload);

        return response()->json(null, 204);
    }
}
