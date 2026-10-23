<?php

namespace App\Http\Resources;

use App\Models\RightsizingRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rightsizing recommendation (docs/monitoring.md#rightsizing): sizes before and after, why, the
 * evidence of the window, what the change frees, the monthly difference when billing is on
 * (savings null otherwise) and whether applying it would be refused (blocked).
 *
 * status: open (a snoozed one whose day has come reads as open) | snoozed | dismissed | applied
 *
 * @mixin RightsizingRecommendation
 */
class RightsizingRecommendationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RightsizingRecommendation $r */
        $r = $this->resource;
        $evidence = (array) $r->evidence;

        return [
            'id' => $r->id,
            'project_id' => $r->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $r->project->id,
                'name' => $r->project->name,
                'slug' => $r->project->slug,
                'service_type' => $r->project->service_type,
            ]),
            'region_id' => $r->region_id,
            /** @var "open"|"snoozed"|"dismissed"|"applied" */
            'status' => $r->status === RightsizingRecommendation::SNOOZED && $r->isVisible() ? RightsizingRecommendation::OPEN : $r->status,
            /** @var "down"|"up"|"mixed" */
            'direction' => $r->direction,
            'current' => ['cpu_m' => $r->current_cpu_m, 'memory_mb' => $r->current_memory_mb],
            'recommended' => ['cpu_m' => $r->recommended_cpu_m, 'memory_mb' => $r->recommended_memory_mb],
            'changes' => [
                'cpu' => (string) ($evidence['changes']['cpu'] ?? 'keep'),
                'memory' => (string) ($evidence['changes']['memory'] ?? 'keep'),
            ],
            /** @var list<string> why: memory_oom_killed, memory_near_limit, memory_over_provisioned, cpu_throttled, cpu_over_provisioned */
            'reasons' => $r->reasons,
            'evidence' => [
                'window_days' => (int) ($evidence['window_days'] ?? 0),
                'from' => $evidence['from'] ?? null,
                'to' => $evidence['to'] ?? null,
                'days' => (int) ($evidence['days'] ?? 0),
                'sessions' => (int) ($evidence['sessions'] ?? 0),
                'always_on' => (bool) ($evidence['always_on'] ?? false),
                'memory_max_mb' => $evidence['memory']['max_mb'] ?? null,
                'memory_p95_mb' => $evidence['memory']['p95_mb'] ?? null,
                'memory_basis' => $evidence['memory']['basis'] ?? 'max',
                'memory_margin' => $evidence['memory']['margin'] ?? null,
                'memory_near_limit' => (bool) ($evidence['memory']['near_limit'] ?? false),
                'cpu_max_m' => $evidence['cpu']['max_m'] ?? null,
                'cpu_p95_m' => $evidence['cpu']['p95_m'] ?? null,
                'cpu_basis' => $evidence['cpu']['basis'] ?? 'p95',
                'cpu_margin' => $evidence['cpu']['margin'] ?? null,
                'oom_kills' => (int) ($evidence['oom_kills'] ?? 0),
                'throttled_ratio' => (float) ($evidence['throttled_ratio'] ?? 0),
            ],
            'freed' => [
                'cpu_m' => (int) ($evidence['freed']['cpu_m'] ?? 0),
                'memory_mb' => (int) ($evidence['freed']['memory_mb'] ?? 0),
                'pods' => (int) ($evidence['freed']['pods'] ?? 1),
                'always_on' => (bool) ($evidence['freed']['always_on'] ?? false),
                'peak_cpu_m' => (int) ($evidence['peak']['cpu_m'] ?? 0),
                'peak_memory_mb' => (int) ($evidence['peak']['memory_mb'] ?? 0),
            ],
            'savings' => is_array($r->savings) ? [
                'basis' => $r->savings['basis'] ?? null,
                'monthly' => isset($r->savings['monthly']) ? (float) $r->savings['monthly'] : null,
                'currency' => $r->savings['currency'] ?? $r->currency,
                'allocation' => $r->savings['allocation'] ?? null,
                'freed_allocation' => $r->savings['freed_allocation'] ?? null,
            ] : null,
            'blocked' => is_array($r->blocked) ? [
                'code' => (string) ($r->blocked['code'] ?? ''),
                'message' => (string) ($r->blocked['message'] ?? ''),
            ] : null,
            /** @var string|null */
            'computed_at' => $r->computed_at?->toIso8601String(),
            /** @var string|null */
            'snoozed_until' => $r->snoozed_until?->toIso8601String(),
            /** @var string|null */
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decided_by' => $r->decided_by,
            'applied_version_id' => $r->applied_version_id,
        ];
    }
}
