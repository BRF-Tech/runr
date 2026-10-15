<?php

namespace App\Services\Ai\Models;

use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use Illuminate\Database\QueryException;

/**
 * CPU / memory model services reserve on a region (current organization): per pod the engine
 * container plus the queue-proxy (and the Kata overhead of the RuntimeClass the service was
 * deployed with — else the region's CaaS mapping), × max(1, min_scale). Stopped services reserve
 * nothing. Counted wherever the projects' reservations are: region admission (RegionCapacity:
 * an always-on service — scale to zero off or min_scale >= 1 — against the always-on budget, a
 * scale-to-zero one with one pod against the FaaS overcommit budget), region usage and capacity
 * alerts (ResourceComputationTrait), the tenant quota (TenantQuotaService).
 */
final class ModelServiceReservations
{
    /**
     * Demand of a model service, split by budget (ResourceReservation::demandForSize()).
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demandFor(AiModelService $service, ?KnativeRegion $region = null): array
    {
        $deployed = $service->getAttribute('runtime_class');

        return ResourceReservation::demandForSize(
            ResourceReservation::parseCpu($service->cpu ?: '1'),
            (int) $service->memory_mb,
            'http',
            (int) $service->min_scale,
            $service->scale_to_zero ?? true,
            ResourceReservation::overheadFor($region ?? $service->region, 'caas', is_string($deployed) ? $deployed : null),
        );
    }

    /** @return array{cpu_m: int, memory_mb: int} */
    public static function forService(AiModelService $service, ?KnativeRegion $region = null): array
    {
        return ResourceReservation::total(self::demandFor($service, $region));
    }

    /** @return array{cpu_m: int, memory_mb: int} */
    public static function sum(KnativeRegion $region, ?string $excludeServiceId = null): array
    {
        return self::sumIn($region->id, $excludeServiceId, $region);
    }

    /**
     * Running model services of one region, or of every region when $regionId is null.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function sumIn(?string $regionId, ?string $excludeServiceId = null, ?KnativeRegion $region = null): array
    {
        return ResourceReservation::total(self::demandIn($regionId, $excludeServiceId, $region));
    }

    /**
     * Demand of the model services that are not stopped, of one region or of every region when
     * $regionId is null.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demandIn(?string $regionId, ?string $excludeServiceId = null, ?KnativeRegion $region = null): array
    {
        $total = ResourceReservation::noDemand();

        try {
            $services = AiModelService::query()
                ->where('status', '!=', AiModelService::STATUS_STOPPED)
                ->when($regionId, fn ($q) => $q->where('knative_region_id', $regionId))
                ->when($excludeServiceId, fn ($q) => $q->whereKeyNot($excludeServiceId))
                ->with('region')
                ->get();
        } catch (QueryException) {
            // a schema whose tenant migrations have not run (no AI tables)
            return $total;
        }

        foreach ($services as $service) {
            $total = ResourceReservation::addDemand($total, self::demandFor($service, $region ?? $service->region));
        }

        return $total;
    }

    /**
     * Region admission of a model service (RegionCapacity): its demand next to the region's
     * projects, sandboxes and the other model services must fit the always-on budget and the
     * FaaS overcommit budget. An existing running service that does not grow always fits.
     * Returns [field => message] of what does not fit.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     * @return array<string, string>
     */
    public static function regionProblems(KnativeRegion $region, array $demand, ?AiModelService $existing = null): array
    {
        if ($existing && $existing->status !== AiModelService::STATUS_STOPPED && $existing->knative_region_id === $region->id) {
            $current = self::demandFor($existing, $region);
            $grows = false;
            foreach (['cpu_m', 'memory_mb'] as $key) {
                $grows = $grows
                    || $demand['hard'][$key] > $current['hard'][$key]
                    || $demand['hard'][$key] + $demand['burst'][$key] > $current['hard'][$key] + $current['burst'][$key];
            }
            if (! $grows) {
                return [];
            }
        }

        $problems = [];
        foreach (RegionCapacity::problems($region, $demand, RegionCapacity::usage($region->id, null, $existing?->id)) as $key => $message) {
            $problems[$key === 'cpu_m' ? 'cpu' : 'memory_mb'] = $message;
        }

        return $problems;
    }
}
