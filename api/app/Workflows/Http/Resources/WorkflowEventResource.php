<?php

namespace App\Workflows\Http\Resources;

use App\Workflows\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkflowEvent */
class WorkflowEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WorkflowEvent $e */
        $e = $this->resource;

        return [
            'id' => $e->id,
            'name' => $e->name,
            'data' => $e->data ?? new \stdClass,
            'external_id' => $e->external_id,
            'source' => $e->source,
            'project_id' => $e->project_id,
            'sent_by' => $e->sent_by,
            'ts' => $e->ts,
            'runs_started' => $e->runs_started,
            'waits_resumed' => $e->waits_resumed,
            'runs_cancelled' => $e->runs_cancelled,
            'created_at' => $e->created_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
