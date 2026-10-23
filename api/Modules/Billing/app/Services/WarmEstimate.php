<?php

namespace Modules\Billing\Services;

use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\BillingPolicy;

/**
 * The cost of a timed warmer's warm hours (App\Support\Projects\WarmEstimates, registered by
 * BillingServiceProvider::wire): in a pay-as-you-go region the warm vCPU-hours and GB-hours at
 * today's prices of the region (with the organization's markup, × the trigger's multiplier), the
 * way a bill prices metered usage (BillCalculator). A fixed region answers its type only — the
 * allocation already covers the schedule's peak. The cost is shown to those who may see prices
 * (billing.view, as on the project's billing tab).
 */
class WarmEstimate
{
    public function __construct(
        private readonly RegionTypes $types,
        private readonly PriceBook $prices,
        private readonly Rbac $rbac,
    ) {}

    /** @return array{billing_type: string, cost: float|null, currency: string|null} */
    public function price(?string $regionId, float $vcpuHours, float $memoryGbHours, string $trigger, ?Authenticatable $viewer): array
    {
        $type = $this->types->typeOf($regionId);
        if ($type !== BillingPolicy::PAYG || ! $viewer || ! $this->rbac->allows($viewer, 'billing.view')) {
            return ['billing_type' => $type, 'cost' => null, 'currency' => null];
        }

        $price = $this->prices->current($regionId);
        $cost = ($vcpuHours * $price->get('cpu_vcpu_hour') + $memoryGbHours * $price->get('memory_gb_hour'))
            * $price->multiplier($trigger)
            * $price->markupFactor();

        return ['billing_type' => $type, 'cost' => round($cost, 2), 'currency' => BillingContext::currency()];
    }
}
