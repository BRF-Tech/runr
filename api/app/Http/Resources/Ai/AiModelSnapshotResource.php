<?php

namespace App\Http\Resources\Ai;

use App\Models\AiModelSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiModelSnapshot */
class AiModelSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ai_model_id' => $this->ai_model_id,
            'knative_region_id' => $this->knative_region_id,
            'region_name' => $this->relationLoaded('region') ? $this->region?->name : null,
            'source' => $this->source,
            'revision' => $this->revision,
            'status' => $this->status,
            'message' => $this->message,
            'size_bytes' => $this->size_bytes,
            'files_count' => $this->files_count,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
