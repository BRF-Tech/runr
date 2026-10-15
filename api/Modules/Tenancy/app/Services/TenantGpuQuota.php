<?php

namespace Modules\Tenancy\Services;

use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuQuota;
use App\Services\Ai\Models\ModelServiceReservations;
use Illuminate\Support\Facades\DB;

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
     * CPU / memory of a model service against the tenant's quota on the region (the projects'
     * and the other model services' reservations, TenantQuotaService::currentUsage()).
     */
    public function checkResources(KnativeRegion $region, array $reservation, ?string $excludeServiceId = null): array
    {
        $tenant = tenancy()->initialized ? tenancy()->tenant : null;
        if (! config('tenancy.enabled') || ! $tenant || $this->exempt() || ($region->region_mode ?? 'managed') === 'infinite') {
            return ['allowed' => true];
        }

        $pivot = DB::connection('central')->table('tenant_regions')
            ->where('tenant_id', $tenant->getTenantKey())
            ->where('knative_region_id', $region->id)
            ->first();

        if (! $pivot) {
            return ['allowed' => false, 'message' => __('api.tenant.no_region_access')];
        }

        $cpuQuota = (int) $pivot->cpu_quota_m;
        $memQuota = (int) $pivot->memory_quota_mb;
        if ($cpuQuota === 0 && $memQuota === 0) {
            return ['allowed' => true];
        }

        $usage = app(TenantQuotaService::class)->currentUsage($region);
        if ($excludeServiceId && ($own = AiModelService::find($excludeServiceId)) && $own->status !== AiModelService::STATUS_STOPPED) {
            $mine = ModelServiceReservations::forService($own, $region);
            $usage['cpu_m'] -= $mine['cpu_m'];
            $usage['memory_mb'] -= $mine['memory_mb'];
        }

        $cpuLeft = $cpuQuota > 0 ? $cpuQuota - $usage['cpu_m'] : PHP_INT_MAX;
        $memLeft = $memQuota > 0 ? $memQuota - $usage['memory_mb'] : PHP_INT_MAX;

        if ($reservation['cpu_m'] > $cpuLeft || $reservation['memory_mb'] > $memLeft) {
            return ['allowed' => false, 'message' => __('api.tenant.quota_exceeded')];
        }

        return ['allowed' => true];
    }

    public function check(KnativeRegion $region, int $gpus, ?string $excludeProjectId = null, ?string $excludeServiceId = null): array
    {
        if ($this->exempt()) {
            return ['allowed' => true, 'quota' => null, 'used' => $this->used($region, $excludeProjectId, $excludeServiceId), 'remaining' => null];
        }

        return parent::check($region, $gpus, $excludeProjectId, $excludeServiceId);
    }
}
