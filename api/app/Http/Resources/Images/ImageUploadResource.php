<?php

namespace App\Http\Resources\Images;

use App\Models\ImageUpload;
use App\Models\KnativeRegion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chunked upload (docs/images.md): send chunks of chunk_size bytes from uploaded_bytes on
 * (PUT …/chunk?offset=) while status is ready, then complete it; image is the uploaded image once
 * status is completed.
 *
 * @mixin ImageUpload
 */
class ImageUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ImageUpload $upload */
        $upload = $this->resource;
        $image = $upload->status === ImageUpload::STATUS_COMPLETED ? $upload->uploadedImage() : null;

        return [
            'id' => $upload->id,
            'name' => "{$upload->repository}:{$upload->tag}",
            'repository' => $upload->repository,
            'tag' => $upload->tag,
            'knative_region_id' => $upload->knative_region_id,
            'region_name' => KnativeRegion::find($upload->knative_region_id)?->name,
            'filename' => $upload->filename,
            'format' => $upload->format,
            'size_bytes' => $upload->size_bytes,
            'sha256' => $upload->sha256,
            'uploaded_bytes' => $upload->uploaded_bytes,
            'chunk_size' => $upload->chunk_size,
            'status' => $upload->status,
            'message' => $upload->message,
            'image' => $image ? (new UploadedImageResource($image))->resolve($request) : null,
            'expires_at' => $upload->expires_at?->toISOString(),
            'completed_at' => $upload->completed_at?->toISOString(),
            'created_at' => $upload->created_at?->toISOString(),
        ];
    }
}
