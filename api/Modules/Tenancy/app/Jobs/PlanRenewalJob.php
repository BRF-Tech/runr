<?php

namespace Modules\Tenancy\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Services\Plans\PlanService;

/**
 * Daily, plans on (docs/billing.md): plan periods that ended roll over and record their next fee,
 * trials convert, prepaid terms and cancelled plans end (PlanService::renewPeriods); tenants whose
 * CPU / memory add-on ended since the last run get their plan quota back.
 */
class PlanRenewalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(BillingSettings $settings, PlanService $plans): void
    {
        if (! $settings->plansEnabled()) {
            return;
        }

        $counts = $plans->renewPeriods();

        // Add-ons that ended in the last two days (a missed run included)
        $tenantIds = Purchase::query()
            ->where('kind', Purchase::KIND_ADDON)
            ->whereNull('voided_at')
            ->whereBetween('ends_at', [now()->subDays(2), now()])
            ->pluck('tenant_id')
            ->unique();

        foreach ($tenantIds as $tenantId) {
            if ($tenant = Tenant::query()->find($tenantId)) {
                $plans->refreshQuota($tenant);
            }
        }

        if ($counts['renewed'] + $counts['ended'] > 0 || $tenantIds->isNotEmpty()) {
            Log::info('Plan periods renewed', $counts + ['addon_quotas' => $tenantIds->count()]);
        }
    }
}
