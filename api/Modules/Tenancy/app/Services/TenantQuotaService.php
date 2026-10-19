<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use Modules\Tenancy\Models\Tenant;

/**
 * Organization quota admission (docs/architecture.md → Region capacity and admission). An
 * organization's quota on a region (tenant_regions.cpu_quota_m / memory_quota_mb, 0 = no limit
 * for that resource) is split into the same two budgets as the region capacity (RegionCapacity),
 * with the region's overcommit factors (faas_cpu_overcommit, default 3; faas_memory_overcommit,
 * default 1.5):
 *
 *  - Always-on budget = the quota. It holds what keeps running (ResourceReservation "hard"):
 *    always-on projects and model services (scale to zero off or min_scale >= 1:
 *    (size + queue-proxy + Kata overhead) × max(1, min_scale)), the Kata pod overhead of
 *    scale-to-zero pods, queue consumers, and the organization's sandboxes and warm pool pods.
 *  - FaaS overcommit budget = quota × the region's factor, minus what the always-on budget holds.
 *    It holds one pod of every scale-to-zero project, model service and git deploy preview
 *    ("burst").
 *
 * A region factor of 1 makes the quota a hard limit for every workload. The quotas on a region
 * add up to at most its capacity (TenantRegionTrait), so quota × factor adds up to at most the
 * region's capacity × factor. Quota model option C (Limits Decisions §4): the configured size × max(1, min_scale)
 * is admitted, max_scale is not; runtime use above that is bounded by the namespace ResourceQuota
 * of the organization ceiling.
 */
class TenantQuotaService
{
    /** Translation group of the refusals (always_on_exceeded / faas_exceeded). */
    public const MESSAGES = 'api.tenant.quota_budget';

    /**
     * Other workloads that hold an organization's region quota next to its projects
     * (e.g. git deploy previews): fn (KnativeRegion $region, ?string $excludeProjectId) → a
     * demand {hard, burst} (ResourceReservation::demandForSize()), or {cpu_m, memory_mb}, which
     * counts in full against the always-on budget.
     *
     * @var list<\Closure>
     */
    protected static array $usageContributors = [];

    public static function addUsageContributor(\Closure $contributor): void
    {
        static::$usageContributors[] = $contributor;
    }

    /**
     * One pod of a project: its own configured CPU/memory plus, on Knative Service pods
     * (trigger http / queue), the queue-proxy sidecar Knative injects — its requests,
     * KnativeConstants::QUEUE_PROXY_CPU_M / QUEUE_PROXY_MEMORY_MB; its CPU limit
     * above the request (QUEUE_PROXY_CPU_LIMIT_M) is not reserved. Schedule (CronJob) pods have no
     * queue-proxy.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function podReservation(int $cpuM, int $memoryMb, ?string $triggerType = 'http'): array
    {
        return ResourceReservation::perPod($cpuM, $memoryMb, $triggerType);
    }

    /**
     * Admitted reservation of a whole project (see class doc), both budgets together.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function projectReservation(int $cpuM, int $memoryMb, ?string $triggerType = 'http', int $minScale = 0): array
    {
        return ResourceReservation::forSize($cpuM, $memoryMb, $triggerType, $minScale);
    }

    /**
     * Check a workload against the tenant's quota on the region: its always-on part against the
     * quota, all of it against quota × the region's factor (see class doc). $reservation is a
     * demand {hard, burst} (ResourceReservation::demandForConfig()) or a reservation
     * {cpu_m, memory_mb}, which counts in full against the always-on budget (sandboxes).
     *
     * On refusal: message (each resource's, naming the budget that is used up), budget (always_on
     * or faas; the CPU's when both resources are short), problems (resource key => message).
     * remaining_cpu_m / remaining_memory_mb, for each resource with a quota: for a refused resource
     * what the refused budget has free; otherwise what is left after this workload of the FaaS
     * budget (a workload with a scale-to-zero part) or of the always-on budget (one without). A
     * resource without a quota (0) is not limited and has no remaining_* entry.
     *
     * @param  array{cpu_m: int, memory_mb: int}|array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $reservation
     * @return array{allowed: bool, message?: string, budget?: string, problems?: array<string, string>, remaining_cpu_m?: int, remaining_memory_mb?: int}
     */
    public function checkReservation(Tenant $tenant, KnativeRegion $region, array $reservation, ?string $excludeProjectId = null, ?string $excludeModelServiceId = null): array
    {
        $pivot = $tenant->regions()
            ->where('knative_region_id', $region->id)
            ->first();

        if (! $pivot) {
            // The organization's own region (its cluster): no organization quota applies there,
            // only the region's capacity
            if ($this->ownsRegion($tenant, $region)) {
                return ['allowed' => true];
            }

            return [
                'allowed' => false,
                'message' => __('api.tenant.no_region_access'),
            ];
        }

        $quota = ['cpu_m' => (int) $pivot->pivot->cpu_quota_m, 'memory_mb' => (int) $pivot->pivot->memory_quota_mb];

        // 0 means unlimited
        if ($quota['cpu_m'] <= 0 && $quota['memory_mb'] <= 0) {
            return ['allowed' => true];
        }

        $demand = self::demandOf($reservation);
        $limits = $this->limits($region, $quota);
        $usage = $this->currentDemand($region, $excludeProjectId, $excludeModelServiceId);
        $shortfalls = RegionCapacity::shortfalls($limits, $usage, $demand);

        $remaining = [];
        foreach (['cpu_m', 'memory_mb'] as $key) {
            // no quota for this resource: nothing is counted down
            if ($limits['capacity'][$key] <= 0) {
                continue;
            }

            $remaining['remaining_'.$key] = match (true) {
                isset($shortfalls[$key]) => $shortfalls[$key]['available'],
                $demand['burst'][$key] > 0 => max(0, $limits['overcommit'][$key] - $usage['hard'][$key] - $usage['burst'][$key] - $demand['hard'][$key] - $demand['burst'][$key]),
                default => max(0, $limits['capacity'][$key] - $usage['hard'][$key] - $demand['hard'][$key]),
            };
        }

        if ($shortfalls !== []) {
            $problems = [];
            foreach ($shortfalls as $key => $shortfall) {
                $problems[$key] = RegionCapacity::message($key, $shortfall, self::MESSAGES);
            }

            return [
                'allowed' => false,
                'message' => implode(' ', $problems),
                'budget' => $shortfalls[array_key_first($shortfalls)]['budget'],
                'problems' => $problems,
            ] + $remaining;
        }

        return ['allowed' => true] + $remaining;
    }

    /**
     * Whether $region is one of the tenant's own regions: owner_tenant_id names the tenant, or —
     * regions created before it was set — the tenant's schema holds it as an unsynced region
     * (platform regions only ever appear there as synced copies). Callers pass the tenant's copy,
     * its own region or the central row; the lookup does not depend on which.
     */
    public function ownsRegion(Tenant $tenant, KnativeRegion $region): bool
    {
        $tenantId = (string) $tenant->getTenantKey();
        $owner = $region->getAttribute('owner_tenant_id');
        if ($owner !== null && $owner !== '') {
            return (string) $owner === $tenantId;
        }

        $lookup = fn () => KnativeRegion::whereKey($region->getKey())
            ->where('is_synced', false)
            ->exists();

        $current = tenancy()->initialized ? tenancy()->tenant : null;

        try {
            return $current && (string) $current->getTenantKey() === $tenantId
                ? $lookup()
                : TenantSchema::inTenant($tenant, $lookup);
        } catch (\Throwable) {
            // No readable tenant database: nothing there is the tenant's own
            return false;
        }
    }

    /**
     * Both budgets of a quota on a region: the quota (always-on) and quota × the region's
     * overcommit factors (always-on + FaaS overcommit).
     *
     * @param  array{cpu_m: int, memory_mb: int}  $quota
     * @return array{capacity: array{cpu_m: int, memory_mb: int}, overcommit: array{cpu_m: int, memory_mb: int}}
     */
    public function limits(KnativeRegion $region, array $quota): array
    {
        return RegionCapacity::limitsOf($quota, RegionCapacity::factors($region));
    }

    /**
     * The quota's budgets with what holds them, in the shape of RegionCapacity::summary() (the
     * projects page and the organization's region list show them). A resource with quota 0 has
     * capacity 0: no limit.
     *
     * @param  array{cpu_m: int, memory_mb: int}  $quota
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}|null  $usage  null: currentDemand($region)
     * @return array<string, array{capacity: int, always_on_used: int, always_on_available: int, faas_overcommit: float, faas_budget: int, faas_used: int, faas_available: int}>
     */
    public function budgets(KnativeRegion $region, array $quota, ?array $usage = null): array
    {
        return RegionCapacity::budgets($this->limits($region, $quota), RegionCapacity::factors($region), $usage ?? $this->currentDemand($region));
    }

    /**
     * Sum the admitted reservations of all projects in the current tenant schema for a region,
     * with its model services and the other contributors (previews), both budgets together.
     * Resources are claimed at project creation, not deployment.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public function currentUsage(KnativeRegion $region, ?string $excludeProjectId = null): array
    {
        return ResourceReservation::total($this->currentDemand($region, $excludeProjectId));
    }

    /**
     * What holds the current tenant's quota on a region, split by budget: its projects (without
     * $excludeProjectId), its model services that are not stopped (without
     * $excludeModelServiceId) and the other contributors (git deploy previews).
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public function currentDemand(KnativeRegion $region, ?string $excludeProjectId = null, ?string $excludeModelServiceId = null): array
    {
        $projects = Project::where('knative_region_id', $region->id)
            ->with('xaasConfig', 'faasConfig', 'containerConfig')
            ->when($excludeProjectId, fn ($q) => $q->where('id', '!=', $excludeProjectId))
            ->get();

        // Model services of the tenant (docs/huggingface.md) reserve CPU / memory too
        $usage = ResourceReservation::addDemand(
            ResourceReservation::sumDemand($projects),
            ModelServiceReservations::demandIn($region->id, $excludeModelServiceId, $region),
        );

        // Other contributors (git deploy previews, addUsageContributor)
        foreach (static::$usageContributors as $contributor) {
            $usage = ResourceReservation::addDemand($usage, self::demandOf((array) $contributor($region, $excludeProjectId)));
        }

        return $usage;
    }

    /**
     * A demand {hard, burst} as it is, a reservation {cpu_m, memory_mb} as always-on.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demandOf(array $reservation): array
    {
        $read = fn (array $part) => ['cpu_m' => (int) ($part['cpu_m'] ?? 0), 'memory_mb' => (int) ($part['memory_mb'] ?? 0)];

        if (isset($reservation['hard']) || isset($reservation['burst'])) {
            return ['hard' => $read((array) ($reservation['hard'] ?? [])), 'burst' => $read((array) ($reservation['burst'] ?? []))];
        }

        return ['hard' => $read($reservation), 'burst' => ResourceReservation::zero()];
    }
}
