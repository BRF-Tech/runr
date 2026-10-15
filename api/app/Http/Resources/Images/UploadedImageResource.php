<?php

namespace App\Http\Resources\Images;

use App\Models\ImageSecurityReport;
use App\Models\KnativeRegion;
use App\Models\UploadedImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An uploaded image (docs/images.md). image_ref pins the digest in the region registry — what a
 * CaaS project or a runtime version runs; tag_ref is the tag (moves when the name is uploaded
 * again). security: the supply-chain report of container images (SBOM, scan, signature).
 *
 * @mixin UploadedImage
 */
class UploadedImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var UploadedImage $image */
        $image = $this->resource;
        $region = KnativeRegion::find($image->knative_region_id);
        $report = $image->kind === UploadedImage::KIND_IMAGE ? ImageSecurityReport::forUploadedImage($image) : null;

        return [
            'id' => $image->id,
            'name' => $image->name(),
            'repository' => $image->repository,
            'tag' => $image->tag,
            'knative_region_id' => $image->knative_region_id,
            'region_name' => $region?->name,
            'image_ref' => $image->imageRef($region),
            'tag_ref' => $image->tagRef($region),
            'digest' => $image->digest,
            'media_type' => $image->media_type,
            'config_media_type' => $image->config_media_type,
            'kind' => $image->kind,
            'source_format' => $image->source_format,
            'size_bytes' => $image->size_bytes,
            'archive_bytes' => $image->archive_bytes,
            'archive_sha256' => $image->archive_sha256,
            'platforms' => $image->platforms ?? [],
            'layers' => $image->layers,
            'source_filename' => $image->source_filename,
            'uploaded_by' => $image->uploaded_by,
            'note' => $image->note,
            'security' => $report?->summary(),
            'created_at' => $image->created_at?->toISOString(),
            'updated_at' => $image->updated_at?->toISOString(),
        ];
    }
}
