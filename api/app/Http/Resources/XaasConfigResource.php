<?php

namespace App\Http\Resources;

use App\Models\XaasConfig;
use App\Triggers\ProjectTriggers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin XaasConfig */
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
            // the schedule trigger's zone (null: the organization's) and the zone it runs in
            ...ProjectTriggers::scheduleTimezoneFields($this->project_id, $this->trigger_type),
            'queue_stream' => $this->queue_stream,
            'scale_to_zero' => $this->scale_to_zero,
            'min_scale' => $this->min_scale,
            'max_scale' => $this->max_scale,
            // Timed warmer rows [{cron, min_scale}] in the organization's time zone; null = off
            'scale_schedule' => $this->scale_schedule ?: null,
            'gpu' => $this->gpu,
        ];
    }
}
