<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Sandbox;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Sandboxes\SandboxContext;
use Illuminate\Database\QueryException;

/**
 * Region capacity admission: two budgets per region and resource (docs/architecture.md → Region
 * capacity and admission).
 *
 *  - Always-on budget = the region capacity (total_cpu_m / total_memory_mb: node allocatable minus
 *    the platform pods, KnativeNodeService), without overcommit. It holds what keeps running
 *    (ResourceReservation::demandForSize() "hard"): always-on projects (scale to zero off or
 *    min_scale >= 1: (size + queue-proxy + Kata overhead) × max(1, min_scale)), the Kata pod
 *    overhead of scale-to-zero projects, queue consumers, model services the same way, and the
 *    live sandboxes and warm pool pods of the Sandbox API (size + Kata overhead).
 *  - FaaS overcommit budget = capacity × the region's overcommit factor (faas_cpu_overcommit,
 *    default 3; faas_memory_overcommit, default 1.5) minus what the always-on budget holds. It
 *    holds one pod of every scale-to-zero project and model service (size + queue-proxy;
 *    "burst"), which run only while they have requests.
 *
 * A workload is admitted when its hard part fits the always-on budget and its hard + burst parts
 * fit what is left of capacity × factor. A factor of 1 is the 0.5.0 behaviour: every reservation
 * against the real capacity. Region capacity 0 (not measured yet) admits everything.
 */
final class RegionCapacity
{
    public const DEFAULT_CPU_OVERCOMMIT = 3.0;

    /** Lower than CPU: memory is not compressible (see docs/architecture.md → Region capacity and admission). */
    public const DEFAULT_MEMORY_OVERCOMMIT = 1.5;

    public const MIN_OVERCOMMIT = 1.0;

    public const MAX_OVERCOMMIT = 10.0;

    public const ALWAYS_ON = 'always_on';

    public const FAAS = 'faas';

    /** resource key => [region capacity column, overcommit column, default factor] */
    private const RESOURCES = [
        'cpu_m' => ['total_cpu_m', 'faas_cpu_overcommit', self::DEFAULT_CPU_OVERCOMMIT],
        'memory_mb' => ['total_memory_mb', 'faas_memory_overcommit', self::DEFAULT_MEMORY_OVERCOMMIT],
    ];

    /**
     * FaaS overcommit factors of a region (the defaults for a row without the columns).
     *
     * @return array{cpu_m: float, memory_mb: float}
     */
    public static function factors(KnativeRegion $region): array
    {
        $factors = [];
        foreach (self::RESOURCES as $key => [, $column, $default]) {
            $value = $region->getAttribute($column);
            $factors[$key] = is_numeric($value)
                ? max(self::MIN_OVERCOMMIT, min(self::MAX_OVERCOMMIT, round((float) $value, 2)))
                : $default;
        }

        return $factors;
    }

    /**
     * Capacity and capacity × factor of one region or several together (WithinResourceLimit
     * without a region: every active region).
     *
     * @param  iterable<KnativeRegion>  $regions
     * @return array{capacity: array{cpu_m: int, memory_mb: int}, overcommit: array{cpu_m: int, memory_mb: int}}
     */
    public static function limits(iterable $regions): array
    {
        $limits = ['capacity' => ResourceReservation::zero(), 'overcommit' => ResourceReservation::zero()];

        foreach ($regions as $region) {
            $factors = self::factors($region);
            foreach (self::RESOURCES as $key => [$column]) {
                $capacity = max(0, (int) $region->getAttribute($column));
                $limits['capacity'][$key] += $capacity;
                $limits['overcommit'][$key] += (int) floor($capacity * $factors[$key]);
            }
        }

        return $limits;
    }

    /**
     * What holds a region (every region when $regionId is null), split by budget: its projects
     * (without $excludeProjectId), its model services that are not stopped (without
     * $excludeModelServiceId) and its live sandboxes and warm pool pods.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function usage(?string $regionId, ?string $excludeProjectId = null, ?string $excludeModelServiceId = null): array
    {
        $projects = Project::with('xaasConfig', 'faasConfig', 'containerConfig')
            ->when($excludeProjectId, fn ($q) => $q->where('id', '!=', $excludeProjectId))
            ->when($regionId, fn ($q) => $q->where('knative_region_id', $regionId))
            ->get();

        $usage = ResourceReservation::addDemand(
            ResourceReservation::sumDemand($projects),
            ModelServiceReservations::demandIn($regionId, $excludeModelServiceId),
        );

        return ResourceReservation::addDemand($usage, ['hard' => self::sandboxUsage($regionId), 'burst' => ResourceReservation::zero()]);
    }

    /**
     * CPU / memory of the live sandboxes and warm pool pods of a region (every region when null):
     * size + Kata overhead of their class, all of it always-on. In an organization's context its
     * own sandboxes (the projects counted next to them are its own too).
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function sandboxUsage(?string $regionId): array
    {
        $total = ResourceReservation::zero();
        $tenantId = SandboxContext::tenancyEnabled() ? SandboxContext::tenantId() : null;

        try {
            $rows = Sandbox::query()
                ->whereIn('status', Sandbox::LIVE_STATUSES)
                ->when($regionId, fn ($q) => $q->where('knative_region_id', $regionId))
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->get(['cpu_m', 'memory_mb', 'sandbox_class']);
        } catch (QueryException) {
            // a schema without the Sandbox API tables
            return $total;
        }

        foreach ($rows as $row) {
            $total = ResourceReservation::add($total, self::sandboxDemand((int) $row->cpu_m, (int) $row->memory_mb, $row->sandbox_class));
        }

        return $total;
    }

    /**
     * One sandbox pod: its size plus the pod overhead of its class (all always-on).
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function sandboxDemand(int $cpuM, int $memoryMb, ?string $class): array
    {
        return ResourceReservation::add(['cpu_m' => $cpuM, 'memory_mb' => $memoryMb], KataRuntime::podOverhead($class));
    }

    /**
     * The budgets a demand does not fit, per resource ('cpu_m' / 'memory_mb'). The always-on
     * budget is checked first; a demand that fits it must also fit the FaaS overcommit budget
     * (capacity × factor minus the always-on usage). A resource with capacity 0 is not checked.
     *
     * @param  array{capacity: array{cpu_m: int, memory_mb: int}, overcommit: array{cpu_m: int, memory_mb: int}}  $limits
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $usage
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     * @return array<string, array{budget: string, needed: int, available: int, total: int, capacity: int, factor: float, reserved: int}>
     */
    public static function shortfalls(array $limits, array $usage, array $demand): array
    {
        $shortfalls = [];

        foreach (array_keys(self::RESOURCES) as $key) {
            $capacity = $limits['capacity'][$key];
            if ($capacity <= 0) {
                continue;
            }

            $hard = $demand['hard'][$key];
            $reserved = $usage['hard'][$key];
            $factor = round($limits['overcommit'][$key] / $capacity, 2);
            $context = ['capacity' => $capacity, 'factor' => $factor, 'reserved' => $reserved];

            if ($hard > 0 && $reserved + $hard > $capacity) {
                $shortfalls[$key] = ['budget' => self::ALWAYS_ON, 'needed' => $hard, 'available' => max(0, $capacity - $reserved), 'total' => $capacity] + $context;

                continue;
            }

            $needed = $hard + $demand['burst'][$key];
            $budget = max(0, $limits['overcommit'][$key] - $reserved);
            if ($needed > 0 && $reserved + $usage['burst'][$key] + $needed > $limits['overcommit'][$key]) {
                $shortfalls[$key] = ['budget' => self::FAAS, 'needed' => $needed, 'available' => max(0, $budget - $usage['burst'][$key]), 'total' => $budget] + $context;
            }
        }

        return $shortfalls;
    }

    /**
     * The refusal of a shortfall: which budget is used up and what is still free.
     *
     * @param  array{budget: string, needed: int, available: int, total: int, capacity: int, factor: float, reserved: int}  $shortfall
     */
    public static function message(string $key, array $shortfall): string
    {
        $cpu = $key === 'cpu_m';
        $unit = fn (int $value) => $cpu ? "{$value}m" : "{$value} MB";

        return __($shortfall['budget'] === self::ALWAYS_ON ? 'api.resource_limit.always_on_exceeded' : 'api.resource_limit.faas_exceeded', [
            'resource' => $cpu ? 'CPU' : __('api.resource_limit.memory'),
            'needed' => $unit($shortfall['needed']),
            'available' => $unit($shortfall['available']),
            'total' => $unit($shortfall['total']),
            'capacity' => $unit($shortfall['capacity']),
            'factor' => rtrim(rtrim(number_format($shortfall['factor'], 2, '.', ''), '0'), '.'),
            'reserved' => $unit($shortfall['reserved']),
        ]);
    }

    /**
     * Admission of a demand on one region: [resource key => message] of what does not fit.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}|null  $usage  null: usage($region->id)
     * @return array<string, string>
     */
    public static function problems(KnativeRegion $region, array $demand, ?array $usage = null): array
    {
        $problems = [];
        foreach (self::shortfalls(self::limits([$region]), $usage ?? self::usage($region->id), $demand) as $key => $shortfall) {
            $problems[$key] = self::message($key, $shortfall);
        }

        return $problems;
    }

    /**
     * Both budgets of a region for the API and the console: per resource the capacity, what the
     * always-on budget holds and has free, the overcommit factor, the FaaS budget (capacity ×
     * factor − always-on), what it holds and has free.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}|null  $usage
     * @return array<string, array{capacity: int, always_on_used: int, always_on_available: int, faas_overcommit: float, faas_budget: int, faas_used: int, faas_available: int}>
     */
    public static function summary(KnativeRegion $region, ?array $usage = null): array
    {
        $limits = self::limits([$region]);
        $usage ??= self::usage($region->id);
        $factors = self::factors($region);

        $summary = [];
        foreach (array_keys(self::RESOURCES) as $key) {
            $capacity = $limits['capacity'][$key];
            $hard = $usage['hard'][$key];
            $budget = max(0, $limits['overcommit'][$key] - $hard);
            $summary[$key] = [
                'capacity' => $capacity,
                'always_on_used' => $hard,
                'always_on_available' => max(0, $capacity - $hard),
                'faas_overcommit' => $factors[$key],
                'faas_budget' => $budget,
                'faas_used' => $usage['burst'][$key],
                'faas_available' => max(0, $budget - $usage['burst'][$key]),
            ];
        }

        return $summary;
    }

    /**
     * How full the fuller of the two budgets is, in percent (capacity alerts): always-on usage of
     * the capacity, or all usage of capacity × factor. Null when the capacity is not known.
     *
     * @param  array{capacity: int, always_on_used: int, faas_overcommit: float, faas_used: int}  $resource  one entry of summary()
     */
    public static function utilization(array $resource): ?float
    {
        if ($resource['capacity'] <= 0) {
            return null;
        }

        $overcommit = (int) floor($resource['capacity'] * $resource['faas_overcommit']);

        return round(max(
            $resource['always_on_used'] / $resource['capacity'],
            ($resource['always_on_used'] + $resource['faas_used']) / max(1, $overcommit),
        ) * 100, 1);
    }
}
