<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XaasConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'filename' => $this->filename,
            'handler' => $this->handler,
            'env_vars' => $this->env_vars,
            'memory_mb' => $this->memory_mb,
            'cpu' => $this->cpu,
            'timeout_seconds' => $this->timeout_seconds,
            'trigger_type' => $this->trigger_type,
            'schedule' => $this->schedule,
            'queue_stream' => $this->queue_stream,
            'scale_to_zero' => $this->scale_to_zero,
            'min_scale' => $this->min_scale,
            'max_scale' => $this->max_scale,
            'gpu' => $this->gpu,
        ];
    }
}
