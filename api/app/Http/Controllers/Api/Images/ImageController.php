<?php

namespace App\Http\Controllers\Api\Images;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\Images\UploadedImageResource;
use App\Models\UploadedImage;
use App\Services\Images\ImageOwner;
use App\Services\Images\ImageQuota;
use App\Services\Images\UploadedImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Uploaded images of the current organization (docs/images.md): list, details, delete, quota.
 * Uploading is ImageUploadController.
 *
 * @tags Images
 */
class ImageController extends Controller
{
    public function __construct(private UploadedImages $images, private ImageQuota $quota) {}

    /** GET /images?knative_region_id=&kind=image|wasm|spin */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('images.view'), 403);
        $data = $request->validate([
            'knative_region_id' => 'sometimes|nullable|string|max:64',
            'kind' => ['sometimes', 'nullable', Rule::in(UploadedImage::KINDS)],
        ]);

        $images = $this->images->visible($data['knative_region_id'] ?? null, $data['kind'] ?? null)
            ->orderBy('repository')->orderBy('tag')->orderBy('knative_region_id')
            ->get();

        return response()->json([
            'data' => UploadedImageResource::collection($images)->resolve($request),
            'quota' => $this->quota->summary(ImageOwner::currentKey()),
        ]);
    }

    public function show(Request $request, UploadedImage $image): JsonResponse
    {
        abort_unless(Gate::allows('images.view'), 403);
        $this->abortUnlessOwned($image);

        return response()->json(['data' => (new UploadedImageResource($image))->resolve($request)]);
    }

    /** GET /images/quota */
    public function quota(): JsonResponse
    {
        abort_unless(Gate::allows('images.view'), 403);

        return response()->json($this->quota->summary(ImageOwner::currentKey()));
    }

    /**
     * DELETE /images/{image}: the record and its manifest in the region registry. Refused (409)
     * while a CaaS project or a runtime version still uses the image.
     */
    public function destroy(UploadedImage $image): JsonResponse
    {
        abort_unless(Gate::allows('images.delete'), 403);
        $this->abortUnlessOwned($image);

        $usages = $this->images->usages($image);
        if ($usages['projects'] !== [] || $usages['runtimes'] !== []) {
            return response()->json([
                'message' => __('images.in_use', [
                    'name' => $image->name(),
                    'used' => implode(', ', array_merge($usages['projects'], array_map(fn ($r) => "runtime {$r}", $usages['runtimes']))),
                ]),
                'code' => 'image_in_use',
                'usages' => $usages,
            ], 409);
        }

        $removed = $this->images->delete($image);

        return response()->json(['deleted' => true, 'registry_removed' => $removed, 'id' => $image->id, 'name' => $image->name()]);
    }

    private function abortUnlessOwned(UploadedImage $image): void
    {
        abort_unless($image->owner_key === ImageOwner::currentKey(), 404);
    }
}
