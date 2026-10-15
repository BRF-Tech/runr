<?php

namespace Modules\Tenancy\Services\Plans;

use App\Models\KnativeRegion;
use App\Services\CacheVersionService;
use Modules\Tenancy\Http\Controllers\Concerns\TenantRegionTrait;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Tenant;

/**
 * Writes tenant region quotas (tenant_regions) for self-service flows — onboarding and plans —
 * with the same no-overcommit rule as the admin's region assignment (TenantRegionTrait): the
 * tenants' quotas on a managed region add up to at most the region capacity and the organization
 * ceiling. Self-service values that do not fit are lowered to what is still free (clamped).
 */
class RegionQuotaAllocator
{
    use TenantRegionTrait;

    /**
     * @return array{cpu: int, memory: int} still free on the region for this tenant
     */
    public function remaining(KnativeRegion $region, string $tenantId): array
    {
        return $this->remainingQuotaCapacity($region, $tenantId);
    }

    /**
     * Assign (or re-quota) a platform region. 0 = unlimited, as everywhere in tenant quotas.
     *
     * @return array{cpu_quota_m: int, memory_quota_mb: int, clamped: bool}
     */
    public function allocate(Tenant $tenant, CentralSyncableRegion $region, int $cpuM, int $memoryMb, bool $attach = true): array
    {
        [$cpuM, $memoryMb, $clamped] = $this->fit($tenant, $region, $cpuM, $memoryMb);

        $values = ['cpu_quota_m' => $cpuM, 'memory_quota_mb' => $memoryMb];

        if ($attach) {
            $tenant->regions()->syncWithoutDetaching([$region->id => $values]);
            // Region row into the tenant's schema (kubeconfig excluded)
            $region->triggerSyncEvent();
        } else {
            $tenant->regions()->updateExistingPivot($region->id, $values);
        }

        app(CacheVersionService::class)->bump('settings');

        return $values + ['clamped' => $clamped];
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    public function fit(Tenant $tenant, KnativeRegion $region, int $cpuM, int $memoryMb): array
    {
        if (($region->region_mode ?? 'managed') === 'infinite') {
            return [max(0, $cpuM), max(0, $memoryMb), false];
        }

        $remaining = $this->remaining($region, (string) $tenant->id);
        $clamped = false;

        // 0 (unlimited) is not part of the capacity sum; positive values must fit
        if ($cpuM > 0 && $cpuM > $remaining['cpu']) {
            $cpuM = max(1, $remaining['cpu']);
            $clamped = true;
        }
        if ($memoryMb > 0 && $memoryMb > $remaining['memory']) {
            $memoryMb = max(1, $remaining['memory']);
            $clamped = true;
        }

        return [$cpuM, $memoryMb, $clamped];
    }
}
