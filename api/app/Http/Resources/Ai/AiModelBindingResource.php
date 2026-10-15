<?php

namespace App\Http\Resources\Ai;

use App\Models\AiModelBinding;
use App\Services\Ai\Models\ModelBindingEnv;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiModelBinding */
class AiModelBindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = $this->relationLoaded('service') ? $this->service : null;
        $project = $this->relationLoaded('project') ? $this->project : null;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $project ? ['id' => $project->id, 'name' => $project->name] : null,
            'ai_model_service_id' => $this->ai_model_service_id,
            'alias' => $this->alias,
            'mount_weights' => (bool) $this->mount_weights,
            'env' => $service ? app(ModelBindingEnv::class)->envFor($this->resource, $project) : [],
            'service' => $service ? (new AiModelServiceResource($service))->resolve() : null,
            'applies_on_next_deploy' => true,
        ];
    }
}
