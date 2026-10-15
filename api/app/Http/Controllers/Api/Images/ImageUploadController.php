<?php

namespace App\Http\Controllers\Api\Images;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\Images\ImageUploadResource;
use App\Models\ImageUpload;
use App\Models\KnativeRegion;
use App\Services\Images\ImageName;
use App\Services\Images\ImageOwner;
use App\Services\Images\ImageQuota;
use App\Services\Images\ImageUploadException;
use App\Services\Images\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Chunked, resumable upload of an image file into a region's registry (docs/images.md):
 *
 *   POST   /images/uploads                    {knative_region_id, filename, size, sha256?, name?, format?} → 201
 *   GET    /images/uploads/{upload}            status (preparing → ready → verifying → pushing → completed | failed)
 *   PUT    /images/uploads/{upload}/chunk      ?offset=N, raw bytes (application/octet-stream)
 *   POST   /images/uploads/{upload}/complete   202; the region verifies and pushes
 *   DELETE /images/uploads/{upload}            abort
 *   GET    /images/uploads                      open uploads (resume after a reload)
 *
 * Chunks go through the Kubernetes API server to the receiver in the region: the API keeps
 * nothing on disk and the region needs no route to RunR. A client resumes after uploaded_bytes.
 *
 * @tags Images
 */
class ImageUploadController extends Controller
{
    public function __construct(private ImageUploadService $uploads) {}

    /** GET /images/uploads — the current organization's open uploads */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('images.upload'), 403);

        $uploads = ImageUpload::query()
            ->where('owner_key', ImageOwner::currentKey())
            ->whereIn('status', ImageUpload::OPEN)
            ->latest()
            ->get();

        return response()->json(['data' => ImageUploadResource::collection($uploads)->resolve($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('images.upload'), 403);

        $max = (int) config('images.max_upload_gb', 20) * ImageQuota::GIB;
        $data = $request->validate([
            'knative_region_id' => 'required|string|exists:knative_regions,id',
            'filename' => 'required|string|max:255',
            'size' => "required|integer|min:1|max:{$max}",
            'sha256' => 'nullable|string|regex:/^[A-Fa-f0-9]{64}$/',
            'name' => 'nullable|string|max:260',
            'format' => ['nullable', Rule::in(ImageUpload::FORMATS)],
        ]);

        $name = isset($data['name']) && trim((string) $data['name']) !== ''
            ? ImageName::parse((string) $data['name'])
            : ImageName::fromFilename($data['filename']);
        if ($name === null) {
            throw ValidationException::withMessages(['name' => [__('images.bad_name')]]);
        }

        $format = $data['format'] ?? null;
        if ($format === null || $format === 'auto') {
            $format = str_ends_with(strtolower($data['filename']), '.wasm') ? 'wasm' : 'auto';
        }

        $region = KnativeRegion::findOrFail($data['knative_region_id']);

        try {
            $upload = $this->uploads->start($region, [
                'repository' => $name[0],
                'tag' => $name[1],
                'filename' => $data['filename'],
                'size' => (int) $data['size'],
                'sha256' => $data['sha256'] ?? null,
                'format' => $format,
            ], (string) $request->user()?->getKey());
        } catch (ImageUploadException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => (new ImageUploadResource($upload))->resolve($request)], 201);
    }

    public function show(Request $request, ImageUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('images.upload'), 403);
        $this->abortUnlessOwned($upload);

        return response()->json(['data' => (new ImageUploadResource($this->uploads->refresh($upload)))->resolve($request)]);
    }

    /** PUT /images/uploads/{upload}/chunk?offset=N — raw body, at most chunk_size bytes */
    public function chunk(Request $request, ImageUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('images.upload'), 403);
        $this->abortUnlessOwned($upload);
        $data = $request->validate(['offset' => 'required|integer|min:0']);

        $upload = $this->readyOrConflict($upload);
        if ($upload instanceof JsonResponse) {
            return $upload;
        }

        try {
            return response()->json($this->uploads->chunk($upload, (int) $data['offset'], $request->getContent()));
        } catch (ImageUploadException $e) {
            return $e->toResponse();
        }
    }

    public function complete(Request $request, ImageUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('images.upload'), 403);
        $this->abortUnlessOwned($upload);

        $upload = $this->readyOrConflict($upload);
        if ($upload instanceof JsonResponse) {
            return $upload;
        }

        try {
            $upload = $this->uploads->complete($upload);
        } catch (ImageUploadException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => (new ImageUploadResource($upload))->resolve($request)], 202);
    }

    public function destroy(ImageUpload $upload): JsonResponse
    {
        abort_unless(Gate::allows('images.upload'), 403);
        $this->abortUnlessOwned($upload);

        if ($upload->isOpen()) {
            $this->uploads->abort($upload);
        }

        return response()->json(null, 204);
    }

    /** The upload, refreshed, when it takes chunks; else the 409 answer. */
    private function readyOrConflict(ImageUpload $upload): ImageUpload|JsonResponse
    {
        if ($upload->status === ImageUpload::STATUS_PREPARING) {
            $upload = $this->uploads->refresh($upload);
        }
        if ($upload->status !== ImageUpload::STATUS_READY) {
            return response()->json([
                'message' => __('images.not_ready', ['status' => $upload->status]),
                'code' => 'upload_not_ready',
                'status' => $upload->status,
            ], 409);
        }

        return $upload;
    }

    private function abortUnlessOwned(ImageUpload $upload): void
    {
        abort_unless($upload->owner_key === ImageOwner::currentKey(), 404);
    }
}
