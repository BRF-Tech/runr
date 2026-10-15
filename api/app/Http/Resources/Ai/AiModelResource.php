<?php

namespace App\Http\Resources\Ai;

use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiModel */
class AiModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'source' => $this->source,
            'hub_id' => $this->hub_id,
            'revision' => $this->revision,
            'revision_sha' => $this->revision_sha,
            'task' => $this->task,
            'library' => $this->library,
            'license' => $this->license,
            'gated' => (bool) $this->gated,
            'private' => (bool) $this->private,
            'size_bytes' => $this->size_bytes,
            'parameters' => $this->parameters,
            'card_summary' => $this->card_summary,
            'tags' => array_values((array) ($this->metadata['tags'] ?? [])),
            'source_ref' => $this->source_ref,
            'created_at' => $this->created_at?->toIso8601String(),
            'snapshots' => $this->relationLoaded('snapshots') ? AiModelSnapshotResource::collection($this->snapshots)->resolve() : [],
            'services_count' => (int) ($this->services_count ?? 0),
        ];
    }
}
