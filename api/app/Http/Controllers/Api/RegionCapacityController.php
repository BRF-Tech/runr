<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\UpdateRegionCapacityRequest;
use App\Models\KnativeRegion;
use App\Services\Helpers\RegionCapacity;
use App\Services\ResourceThresholdService;
use Illuminate\Http\JsonResponse;

/**
 * A region's capacity budgets and its FaaS overcommit factors (docs/architecture.md → Region
 * capacity and admission): the always-on budget (the region capacity, no overcommit) and the
 * FaaS overcommit budget (capacity × factor minus the always-on reservations) — and both with the
 * stopped projects back (with_stopped): a stopped project holds nothing of a pay-as-you-go
 * region, this is what the region would need if every one of them were resumed at the same time,
 * the figure the capacity alarm watches (ResourceThresholdService).
 *
 * @tags Knative Regions
 */
class RegionCapacityController extends Controller
{
    public function __construct(private readonly ResourceThresholdService $thresholds) {}

    /**
     * Get the region's capacity budgets, overcommit factors and utilization
     *
     * Both budgets per resource, the factors and how full they are; with_stopped: the same with
     * every stopped project of the region resumed at once.
     */
    public function show(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->getAttribute('is_synced')) {
            return response()->json(['message' => __('api.region.capacity_platform_managed')], 403);
        }

        return response()->json($this->payload($knativeRegion));
    }

    /**
     * Set the region's FaaS overcommit factors.
     *
     * 1 = no overcommit, every reservation against the capacity.
     */
    public function update(UpdateRegionCapacityRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $knativeRegion->update($request->validated());

        return response()->json(['message' => __('api.region.capacity_updated')] + $this->payload($knativeRegion->fresh()));
    }

    /**
     * @return array{data: array{region_id: string, faas_cpu_overcommit: float, faas_memory_overcommit: float, cpu_m: array<string, mixed>, memory_mb: array<string, mixed>, with_stopped: array{projects: int, held: bool, thresholds: array{warning: int, critical: int}, cpu_m: array{stopped_always_on: int, stopped_faas: int, always_on_used: int, faas_used: int, utilization: float|null, budget: string|null, exceeded_by: int, level: string|null, add_capacity: int}, memory_mb: array{stopped_always_on: int, stopped_faas: int, always_on_used: int, faas_used: int, utilization: float|null, budget: string|null, exceeded_by: int, level: string|null, add_capacity: int}, stopped_projects: list<array{id: string, name: string, stopped: bool, cpu_m: int, memory_mb: int}>}}}
     */
    private function payload(KnativeRegion $region): array
    {
        // What the capacity alarm measures: on a multi-tenant installation a platform region adds up
        // every tenant's and the platform's own (TenantResourceThresholdService::measureAcross())
        $measure = $this->thresholds->measure($region);
        $summary = RegionCapacity::summary($region, $measure['usage']);
        $factors = RegionCapacity::factors($region);

        return ['data' => [
            'region_id' => $region->id,
            'faas_cpu_overcommit' => $factors['cpu_m'],
            'faas_memory_overcommit' => $factors['memory_mb'],
            'cpu_m' => $summary['cpu_m'] + ['utilization' => RegionCapacity::utilization($summary['cpu_m'])],
            'memory_mb' => $summary['memory_mb'] + ['utilization' => RegionCapacity::utilization($summary['memory_mb'])],
            // every stopped project of the region resumed at once (StoppedCapacity)
            'with_stopped' => $this->thresholds->withStopped($region, $measure['usage'], $measure['stopped']),
        ]];
    }
}
