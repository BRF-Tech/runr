<?php

namespace App\Http\Resources\Ai;

use App\Models\AiModelService;
use App\Services\Ai\Engines\EngineCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiModelService */
class AiModelServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $model = $this->relationLoaded('model') ? $this->model : null;
        $task = $model?->task;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_name' => $this->service_name,
            'ai_model_id' => $this->ai_model_id,
            'model' => $model ? [
                'id' => $model->id,
                'name' => $model->name,
                'hub_id' => $model->hub_id,
                'task' => $model->task,
                'library' => $model->library,
                'size_bytes' => $model->size_bytes,
                'source' => $model->source,
            ] : null,
            'knative_region_id' => $this->knative_region_id,
            'region_name' => $this->relationLoaded('region') ? $this->region?->name : null,
            'engine' => $this->engine,
            'device' => $this->device,
            'mode' => $this->mode,
            'api' => EngineCatalog::api($this->engine),
            'gpu' => $this->gpu,
            'cpu' => $this->cpu,
            'memory_mb' => $this->memory_mb,
            'min_scale' => $this->min_scale,
            'max_scale' => $this->max_scale,
            'scale_to_zero' => (bool) $this->scale_to_zero,
            'timeout_seconds' => $this->timeout_seconds,
            'image' => $this->image,
            'args' => $this->args,
            'env_vars' => $this->env_vars,
            'port' => $this->port,
            'health_path' => $this->health_path,
            'served_name' => $this->served_name,
            'status' => $this->status,
            'status_message' => $this->status_message,
            // Kubernetes' reason the pods do not start: CrashLoopBackOff, ImagePullBackOff, ErrImagePull, OOMKilled, Unschedulable …
            'status_reason' => $this->status_reason,
            'url' => $this->relationLoaded('region') && $this->region ? $this->resource->internalUrl() : null,
            'openai_base' => $this->relationLoaded('region') && $this->region && EngineCatalog::hasOpenAiRoutes($this->engine, $task)
                ? $this->resource->internalUrl().'/v1'
                : null,
            'playground' => EngineCatalog::playground($this->engine, $task),
            'cold_start' => [
                'size_bytes' => $model?->size_bytes,
                'suggest_min_scale' => EngineCatalog::suggestMinScale($this->device, $model?->size_bytes) && (int) $this->min_scale === 0 && $this->scale_to_zero,
            ],
            'bindings_count' => (int) ($this->bindings_count ?? 0),
            'deployed_at' => $this->deployed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
