<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaasConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'handler' => $this->handler,
            'trigger_type' => $this->trigger_type,
            'schedule' => $this->schedule,
            'queue_stream' => $this->queue_stream,
            'env_vars' => $this->env_vars,
            'memory_mb' => $this->memory_mb,
            'cpu' => $this->cpu,
            'timeout_seconds' => $this->timeout_seconds,
            'scale_to_zero' => $this->scale_to_zero,
            'min_scale' => $this->min_scale,
            'max_scale' => $this->max_scale,
            'gpu' => $this->gpu,
        ];
    }
}
