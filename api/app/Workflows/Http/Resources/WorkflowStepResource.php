<?php

namespace App\Workflows\Http\Resources;

use App\Workflows\Models\WorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkflowStep */
class WorkflowStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WorkflowStep $s */
        $s = $this->resource;

        return [
            'id' => $s->id,
            'step_key' => $s->step_key,
            'name' => $s->name,
            'type' => $s->type,
            'status' => $s->status,
            'position' => $s->position,
            'attempt' => $s->attempt,
            'max_attempts' => $s->max_attempts,
            'input' => $s->input,
            'output' => $s->output,
            'error' => $s->error,
            'attempts' => $s->attempts ?? [],
            'wake_at' => $s->wake_at?->toIso8601ZuluString('millisecond'),
            'timeout_at' => $s->timeout_at?->toIso8601ZuluString('millisecond'),
            'wait_event' => $s->wait_event,
            'wait_expression' => $s->wait_expression,
            'child_run_id' => $s->child_run_id,
            'started_at' => $s->started_at?->toIso8601ZuluString('millisecond'),
            'ended_at' => $s->ended_at?->toIso8601ZuluString('millisecond'),
            'duration_ms' => $s->duration_ms,
        ];
    }
}
