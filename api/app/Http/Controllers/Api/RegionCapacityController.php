<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\UpdateRegionCapacityRequest;
use App\Models\KnativeRegion;
use App\Services\Helpers\RegionCapacity;
use Illuminate\Http\JsonResponse;

/**
 * A region's capacity budgets and its FaaS overcommit factors (docs/architecture.md → Region
 * capacity and admission): the always-on budget (the region capacity, no overcommit) and the
 * FaaS overcommit budget (capacity × factor minus the always-on reservations).
 *
 * @tags Knative Regions
 */
class RegionCapacityController extends Controller
{
    /** Both budgets per resource, the factors and how full they are. */
    public function show(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->getAttribute('is_synced')) {
            return response()->json(['message' => __('api.region.capacity_platform_managed')], 403);
        }

        return response()->json($this->payload($knativeRegion));
    }

    /** Set the FaaS overcommit factors (1 = no overcommit, every reservation against the capacity). */
    public function update(UpdateRegionCapacityRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $knativeRegion->update($request->validated());

        return response()->json(['message' => __('api.region.capacity_updated')] + $this->payload($knativeRegion->fresh()));
    }

    private function payload(KnativeRegion $region): array
    {
        $summary = RegionCapacity::summary($region);
        $factors = RegionCapacity::factors($region);

        return ['data' => [
            'region_id' => $region->id,
            'faas_cpu_overcommit' => $factors['cpu_m'],
            'faas_memory_overcommit' => $factors['memory_mb'],
            'cpu_m' => $summary['cpu_m'] + ['utilization' => RegionCapacity::utilization($summary['cpu_m'])],
            'memory_mb' => $summary['memory_mb'] + ['utilization' => RegionCapacity::utilization($summary['memory_mb'])],
        ]];
    }
}
