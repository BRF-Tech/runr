<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AaasConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'filename' => $this->filename,
            'handler' => $this->handler,
            'config_mode' => $this->config_mode ?? 'blueprint',
            'blueprint_graph' => $this->blueprint_graph,
            'env_vars' => $this->env_vars,
            'memory_mb' => $this->memory_mb,
            'cpu' => $this->cpu,
            'timeout_seconds' => $this->timeout_seconds,
            'trigger_type' => $this->trigger_type,
            'schedule' => $this->schedule,
            'queue_stream' => $this->queue_stream,
            'event_source_project_id' => $this->event_source_project_id,
            'scale_to_zero' => $this->scale_to_zero,
            'min_scale' => $this->min_scale,
            'max_scale' => $this->max_scale,
        ];
    }
}
