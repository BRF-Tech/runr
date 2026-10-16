<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuQuota;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\Tenant;

/**
 * GPU quota of the current tenant on a region (tenant_regions.gpu_quota, central): null = no GPU
 * limit, 0 = no GPUs. Regions in "infinite" mode and requests outside tenant context have no
 * quota; platform admins are exempt (as with CPU / memory quotas, CheckTenantQuota).
 * Bound over App\Services\Ai\Gpu\GpuQuota by the Tenancy module.
 */
class TenantGpuQuota extends GpuQuota
{
    public function quota(KnativeRegion $region): ?int
    {
        if (! config('tenancy.enabled') || ($region->region_mode ?? 'managed') === 'infinite') {
            return null;
        }

        $tenant = tenancy()->initialized ? tenancy()->tenant : null;
        if (! $tenant) {
            return null;
        }

        $quota = DB::connection('central')->table('tenant_regions')
            ->where('tenant_id', $tenant->getTenantKey())
            ->where('knative_region_id', $region->id)
            ->value('gpu_quota');

        return $quota === null ? null : (int) $quota;
    }

    public function exempt(): bool
    {
        return (bool) (auth()->user()?->is_admin ?? false);
    }

    /**
     * CPU / memory of a model service against the tenant's quota on the region
     * (TenantQuotaService): an always-on service in full against the quota, a scale-to-zero one
     * with one pod against quota × the region's overcommit factor, next to the projects, the
     * other model services and the previews. $excludeServiceId: the service itself (a change).
     */
    public function checkResources(KnativeRegion $region, array $demand, ?string $excludeServiceId = null): array
    {
        $tenant = tenancy()->initialized ? tenancy()->tenant : null;
        if (! config('tenancy.enabled') || ! $tenant instanceof Tenant || $this->exempt() || ($region->region_mode ?? 'managed') === 'infinite') {
            return ['allowed' => true];
        }

        return app(TenantQuotaService::class)->checkReservation($tenant, $region, $demand, null, $excludeServiceId);
    }

    public function check(KnativeRegion $region, int $gpus, ?string $excludeProjectId = null, ?string $excludeServiceId = null): array
    {
        if ($this->exempt()) {
            return ['allowed' => true, 'quota' => null, 'used' => $this->used($region, $excludeProjectId, $excludeServiceId), 'remaining' => null];
        }

        return parent::check($region, $gpus, $excludeProjectId, $excludeServiceId);
    }
}
