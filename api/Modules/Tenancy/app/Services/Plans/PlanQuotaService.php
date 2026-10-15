<?php

namespace Modules\Tenancy\Services\Plans;

use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Tenant;

/**
 * Plan → tenant quota mapping: a plan's CPU / memory become the tenant's quota on every platform
 * region it uses (tenant_regions.cpu_quota_m / memory_quota_mb — the quota CheckTenantQuota
 * admits projects against). A null (unlimited) plan limit becomes 0, the quota mechanism's
 * "unlimited". Running add-ons (docs/billing.md) add their CPU / memory on top of a limited plan.
 * The other limits (projects, GPU, invocations, storage) and the feature flags are enforced by
 * EnforcePlanLimits.
 */
class PlanQuotaService
{
    public function __construct(
        private readonly RegionQuotaAllocator $allocator,
        private readonly PlanLimitService $limits,
    ) {}

    /**
     * @return array{cpu_quota_m: int, memory_quota_mb: int}
     */
    public static function quotaOf(Plan $plan): array
    {
        return [
            'cpu_quota_m' => max(0, (int) ($plan->cpu_quota_m ?? 0)),
            'memory_quota_mb' => max(0, (int) ($plan->memory_quota_mb ?? 0)),
        ];
    }

    /**
     * Write the plan's quota onto every region attached to the tenant.
     *
     * @return list<string> ids of regions where the quota had to be lowered to fit the capacity
     */
    public function apply(Tenant $tenant, Plan $plan): array
    {
        $quota = self::quotaOf($plan);
        $addons = $this->limits->addonLimits($tenant);
        foreach (['cpu_quota_m', 'memory_quota_mb'] as $limit) {
            if ($quota[$limit] > 0) {
                $quota[$limit] += (int) ($addons[$limit] ?? 0);
            }
        }
        $clamped = [];

        foreach ($tenant->regions()->get() as $region) {
            $central = $region instanceof CentralSyncableRegion ? $region : CentralSyncableRegion::find($region->id);
            if (! $central) {
                continue;
            }

            $result = $this->allocator->allocate($tenant, $central, $quota['cpu_quota_m'], $quota['memory_quota_mb'], false);
            if ($result['clamped']) {
                $clamped[] = (string) $central->id;
            }
        }

        if ($clamped) {
            Log::warning('Plan quota lowered to the free region capacity', [
                'tenant' => $tenant->slug, 'plan' => $plan->slug, 'regions' => $clamped,
            ]);
        }

        return $clamped;
    }
}
