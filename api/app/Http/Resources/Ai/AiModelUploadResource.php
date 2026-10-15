<?php

namespace App\Http\Resources\Ai;

use App\Models\AiModelUpload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiModelUpload */
class AiModelUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ai_model_id' => $this->ai_model_id,
            'knative_region_id' => $this->knative_region_id,
            'status' => $this->status,
            'chunk_size' => $this->chunk_size,
            'files' => array_map(fn ($f) => [
                'path' => $f['path'],
                'size' => (int) $f['size'],
                'uploaded_bytes' => (int) ($f['uploaded_bytes'] ?? 0),
            ], (array) $this->files),
            'snapshot_id' => $this->snapshot_id,
            'message' => $this->message,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
