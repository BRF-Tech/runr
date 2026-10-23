<?php

namespace Modules\Tenancy\Services\OrgTransfer;

use App\Models\KnativeRegion;
use App\Services\Helpers\ResourceReservation;
use App\Services\OrgTransfer\Contracts\ImportAdmission;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * The tenant's quota on a region for the projects an import creates there (docs/org-export.md):
 * the demand of every project planned into the region so far, summed per budget, checked as one
 * new reservation (TenantQuotaService::checkReservation(), as CheckTenantQuota does for a single
 * new project). Regions without a quota (the tenant's own, "infinite") never refuse.
 */
class TenantImportAdmission implements ImportAdmission
{
    public function __construct(private TenantQuotaService $quotas) {}

    public function check(KnativeRegion $region, array $demands): ?string
    {
        $tenant = tenancy()->tenant;
        if (! $tenant instanceof Tenant || $region->region_mode === 'infinite') {
            return null;
        }

        $sum = ['hard' => ['cpu_m' => 0, 'memory_mb' => 0], 'burst' => ['cpu_m' => 0, 'memory_mb' => 0]];
        foreach ($demands as $demand) {
            $one = ResourceReservation::demandForConfig($demand['config'], $demand['service_type'], $region);
            foreach (['hard', 'burst'] as $budget) {
                $sum[$budget]['cpu_m'] += $one[$budget]['cpu_m'];
                $sum[$budget]['memory_mb'] += $one[$budget]['memory_mb'];
            }
        }

        $result = $this->quotas->checkReservation($tenant, $region, $sum);

        return $result['allowed'] ? null : (string) ($result['message'] ?? __('api.tenant.quota_exceeded'));
    }
}
