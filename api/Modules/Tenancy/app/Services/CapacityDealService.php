<?php

namespace Modules\Tenancy\Services;

use Illuminate\Support\Collection;
use Modules\Tenancy\Models\CapacityDeal;

class CapacityDealService
{
    /**
     * Create a new capacity deal for a tenant.
     */
    public function createDeal(string $tenantId, string $regionId, array $data): CapacityDeal
    {
        return CapacityDeal::create([
            'tenant_id' => $tenantId,
            'region_id' => $regionId,
            'cpu_m' => $data['cpu_m'],
            'memory_mb' => $data['memory_mb'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'monthly_price' => $data['monthly_price'],
            'currency' => $data['currency'] ?? 'USD',
            'notes' => $data['notes'] ?? null,
            'infra_cost' => $data['infra_cost'] ?? null,
        ]);
    }

    /**
     * Cancel an active capacity deal.
     */
    public function cancelDeal(CapacityDeal $deal): void
    {
        $deal->update(['status' => 'cancelled']);
    }

    /**
     * Get profit/loss analysis for a region.
     */
    public function getProfitLoss(string $regionId, string $from, string $to): array
    {
        $deals = CapacityDeal::where('region_id', $regionId)
            ->where('status', 'active')
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->get();

        $totalRevenue = $deals->sum('monthly_price');
        $totalInfraCost = $deals->sum('infra_cost');

        return [
            'revenue' => round((float) $totalRevenue, 2),
            'infra_cost' => round((float) $totalInfraCost, 2),
            'profit' => round((float) ($totalRevenue - $totalInfraCost), 2),
            'deal_count' => $deals->count(),
            'currency' => $deals->first()?->currency ?? 'USD',
        ];
    }

    /**
     * Get deals expiring within N days.
     */
    public function getExpiringDeals(int $withinDays = 30): Collection
    {
        return CapacityDeal::where('status', 'active')
            ->where('end_date', '<=', now()->addDays($withinDays))
            ->where('end_date', '>=', now())
            ->with('region')
            ->get();
    }

    /**
     * Expire deals whose end date has passed (scheduled hourly, TenancyServiceProvider). The end
     * date is the deal's last day: a deal ending today stays active until the day is over.
     */
    public function expireDeals(): int
    {
        return CapacityDeal::where('status', 'active')
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => 'expired']);
    }
}
