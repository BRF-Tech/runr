<?php

namespace Modules\Billing\Services;

use App\Models\Project;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Money;

/**
 * The monthly difference of a rightsizing recommendation (docs/billing.md#rightsizing), registered
 * with App\Services\Rightsizing\Savings while billing is on. Positive: saved; negative: costs more.
 *
 *  - pay as you go, always-on workload (basis "reserved"): the size difference × the pods it keeps
 *    running × the hours of a month (730) at the region's vCPU-hour and GB-hour prices of today,
 *    × the trigger's multiplier × (1 + markup) — what its reservation is metered and billed at
 *  - pay as you go, scale-to-zero (basis "usage"): billed for what its sessions use, not for its
 *    size — 0; the smaller size frees capacity
 *  - fixed-price region (basis "fixed"): the allocation is billed, not the usage; the change of
 *    the worst case (WorkloadSizing::peak) is what the allocation could be lowered (or must be
 *    raised) by, priced at the region's monthly prices — saved only once the allocation changes
 */
class RightsizingPricer
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly RegionTypes $types,
        private readonly FixedRequests $requests,
        private readonly BillCalculator $calculator,
    ) {}

    /**
     * @param  array{region_id: string|null, trigger_type: string, always_on: bool, pods: int, hours: int,
     *     delta: array{cpu_m: int, memory_mb: int}, peak_delta: array{cpu_m: int, memory_mb: int}}  $change
     * @return array<string, mixed>|null
     */
    public function price(Project $project, array $change): ?array
    {
        $regionId = $change['region_id'] ?? null;
        $currency = BillingContext::currency();

        if ($this->types->isFixed($regionId)) {
            $peak = $change['peak_delta'];
            $allocation = $regionId !== null ? $this->requests->active((string) $project->id, $regionId) : null;
            // CPU and memory priced apart: one may be freed while the other grows
            $today = CarbonImmutable::today();
            $cpu = $this->calculator->monthlyPrice($regionId, abs($peak['cpu_m']), 0, 0, $today)['monthly_price'];
            $memory = $this->calculator->monthlyPrice($regionId, 0, abs($peak['memory_mb']), 0, $today)['monthly_price'];

            return [
                'basis' => 'fixed',
                'monthly' => round(($peak['cpu_m'] < 0 ? -$cpu : $cpu) + ($peak['memory_mb'] < 0 ? -$memory : $memory), 2),
                'currency' => $currency,
                'allocation' => $allocation ? ['cpu_m' => (int) $allocation->cpu_m, 'memory_mb' => (int) $allocation->memory_mb] : null,
                'freed_allocation' => ['cpu_m' => (int) $peak['cpu_m'], 'memory_mb' => (int) $peak['memory_mb']],
            ];
        }

        if (! $change['always_on']) {
            return ['basis' => 'usage', 'monthly' => 0.0, 'currency' => $currency];
        }

        $price = $this->prices->current($regionId);
        $hours = BigDecimal::of(max(1, (int) $change['hours']))->multipliedBy(max(1, (int) $change['pods']));
        $trigger = $change['trigger_type'] === 'workflow' ? 'http' : $change['trigger_type'];

        $cpu = BigDecimal::of($change['delta']['cpu_m'])->exactlyDividedBy(1000)->multipliedBy($price->exact('cpu_vcpu_hour'));
        $memory = BigDecimal::of($change['delta']['memory_mb'])->exactlyDividedBy(1024)->multipliedBy($price->exact('memory_gb_hour'));
        $amount = $cpu->plus($memory)->multipliedBy($hours)
            ->multipliedBy($price->multiplierExact($trigger))
            ->multipliedBy($price->markupFactorExact());

        return [
            'basis' => 'reserved',
            'monthly' => Money::roundedFloat($amount),
            'currency' => $currency,
        ];
    }
}
