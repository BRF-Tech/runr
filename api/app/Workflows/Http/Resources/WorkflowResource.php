<?php

namespace App\Workflows\Http\Resources;

use App\Workflows\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workflow */
class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Workflow $w */
        $w = $this->resource;

        return [
            'id' => $w->id,
            'project_id' => $w->project_id,
            'name' => $w->name,
            'description' => $w->description,
            'triggers' => $w->triggers ?? [],
            'retries' => $w->retries(),
            'timeout' => $w->config['timeout'] ?? null,
            'concurrency' => $w->concurrencyLimits(),
            'idempotency' => $w->idempotencyExpression(),
            'cancel_on' => $w->config['cancel_on'] ?? [],
            'is_active' => $w->is_active,
            'archived' => $w->archived_at !== null,
            'archived_at' => $w->archived_at?->toIso8601String(),
            'source' => $w->source,
            'synced_at' => $w->synced_at?->toIso8601String(),
            'run_counts' => $this->when(isset($w->run_counts), fn () => $w->run_counts),
            'last_run_at' => $this->when(isset($w->last_run_at), fn () => $w->last_run_at),
            'created_at' => $w->created_at?->toIso8601String(),
        ];
    }
}
