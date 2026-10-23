<?php

namespace App\Workflows\Http\Resources;

use App\Workflows\Models\WorkflowRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run. The list view carries the event name only; the detail view (with `steps` loaded)
 * carries the full event, output and the steps.
 *
 * @mixin WorkflowRun
 */
class WorkflowRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WorkflowRun $r */
        $r = $this->resource;
        $detail = $r->relationLoaded('steps');
        $event = $r->event;

        return [
            'id' => $r->id,
            'workflow' => $r->relationLoaded('workflow') && $r->workflow
                ? ['id' => $r->workflow->id, 'name' => $r->workflow->name]
                : ['id' => $r->workflow_id],
            'project_id' => $r->project_id,
            'environment_id' => $r->getAttribute('environment_id'),
            'environment' => $r->getAttribute('environment') ?? 'production',
            'status' => $r->status,
            'trigger_source' => $r->trigger_source,
            'event_id' => $r->event_id,
            'event_name' => is_object($event) ? ($event->name ?? null) : null,
            'event' => $this->when($detail, fn () => $event),
            'output' => $this->when($detail, fn () => $r->output),
            'error' => $r->error,
            'attempt' => $r->attempt,
            'retry_count' => $r->retry_count,
            'idempotency_key' => $r->idempotency_key,
            'concurrency_key' => $r->concurrency_key,
            'parent_run_id' => $r->parent_run_id,
            'replay_of_run_id' => $r->replay_of_run_id,
            'replay_from_step' => $r->replay_from_step,
            'started_by' => $r->started_by,
            'next_attempt_at' => $r->next_attempt_at?->toIso8601ZuluString('millisecond'),
            'timeout_at' => $r->timeout_at?->toIso8601ZuluString('millisecond'),
            'started_at' => $r->started_at?->toIso8601ZuluString('millisecond'),
            'ended_at' => $r->ended_at?->toIso8601ZuluString('millisecond'),
            'duration_ms' => $r->durationMs(),
            'steps_count' => $r->steps_count,
            'created_at' => $r->created_at?->toIso8601ZuluString('millisecond'),
            'steps' => $this->when($detail, fn () => WorkflowStepResource::collection($r->steps)),
            'children' => $this->when($r->relationLoaded('children'), fn () => $r->children->map(fn (WorkflowRun $c) => [
                'id' => $c->id,
                'status' => $c->status,
            ])->values()),
        ];
    }
}
