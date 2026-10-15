<?php

namespace App\Services\Ai\Models;

use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Helpers\ResourceReservation;
use Illuminate\Database\QueryException;

/**
 * CPU / memory model services reserve on a region (current organization): per pod the engine
 * container plus the queue-proxy (and the Kata overhead of the CaaS mapping), × max(1,
 * min_scale). Stopped services reserve nothing. Counted wherever the projects' reservations are:
 * the region no-overcommit check (WithinResourceLimit, model service admission), region usage
 * and capacity alerts (ResourceComputationTrait), the tenant quota (TenantQuotaService).
 */
final class ModelServiceReservations
{
    /** @return array{cpu_m: int, memory_mb: int} */
    public static function forService(AiModelService $service, ?KnativeRegion $region = null): array
    {
        return ResourceReservation::forSize(
            ResourceReservation::parseCpu($service->cpu ?: '1'),
            (int) $service->memory_mb,
            'http',
            $service->scale_to_zero ? $service->min_scale : max(1, (int) $service->min_scale),
            ResourceReservation::podOverhead($region ?? $service->region, 'caas'),
        );
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
        $total = ResourceReservation::zero();

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
            $r = self::forService($service, $region ?? $service->region);
            $total['cpu_m'] += $r['cpu_m'];
            $total['memory_mb'] += $r['memory_mb'];
        }

        return $total;
    }

    /**
     * Region no-overcommit check of a model service: its reservation next to the region's
     * projects and the other running model services must fit the region capacity
     * (total_cpu_m / total_memory_mb; 0 = unknown, no check). An existing running service that
     * does not grow always fits. Returns [field => message] of what does not fit.
     *
     * @param  array{cpu_m: int, memory_mb: int}  $reservation
     * @return array<string, string>
     */
    public static function regionProblems(KnativeRegion $region, array $reservation, ?AiModelService $existing = null): array
    {
        if ($existing && $existing->status !== AiModelService::STATUS_STOPPED && $existing->knative_region_id === $region->id) {
            $current = self::forService($existing, $region);
            if ($reservation['cpu_m'] <= $current['cpu_m'] && $reservation['memory_mb'] <= $current['memory_mb']) {
                return [];
            }
        }

        $projects = ResourceReservation::sum(
            Project::where('knative_region_id', $region->id)->with('xaasConfig', 'faasConfig', 'containerConfig')->get()
        );
        $services = self::sumIn($region->id, $existing?->id, $region);

        $problems = [];
        foreach (['cpu_m' => ['cpu', (int) $region->total_cpu_m], 'memory_mb' => ['memory_mb', (int) $region->total_memory_mb]] as $key => [$field, $limit]) {
            if ($limit <= 0) {
                continue;
            }
            $used = $projects[$key] + $services[$key];
            if ($used + $reservation[$key] > $limit) {
                $problems[$field] = __('ai.models.region_capacity', [
                    'resource' => $key === 'cpu_m' ? 'CPU' : 'memory',
                    'needed' => $reservation[$key].($key === 'cpu_m' ? 'm' : ' MiB'),
                    'available' => max(0, $limit - $used).($key === 'cpu_m' ? 'm' : ' MiB'),
                    'total' => $limit.($key === 'cpu_m' ? 'm' : ' MiB'),
                ]);
            }
        }

        return $problems;
    }
}
