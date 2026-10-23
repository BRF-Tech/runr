<?php

namespace App\Support\Projects;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a timed warmer costs, for its preview (ScaleScheduleController): the Billing module
 * registers the pricer while billing is on — the region's billing type and, in pay-as-you-go
 * regions, the cost of the warm vCPU-hours and GB-hours at the region's prices for a viewer who
 * may see prices. Without billing there is no estimate.
 */
final class WarmEstimates
{
    /** @var (Closure(?string, float, float, string, ?Authenticatable): (array{billing_type: string, cost: float|null, currency: string|null}|null))|null */
    private static ?Closure $pricer = null;

    /**
     * @param  (Closure(?string $regionId, float $vcpuHours, float $memoryGbHours, string $trigger, ?Authenticatable $viewer): (array{billing_type: string, cost: float|null, currency: string|null}|null))|null  $pricer
     */
    public static function priceWith(?Closure $pricer): void
    {
        self::$pricer = $pricer;
    }

    /** @return array{billing_type: string, cost: float|null, currency: string|null}|null */
    public static function price(?string $regionId, float $vcpuHours, float $memoryGbHours, string $trigger, ?Authenticatable $viewer): ?array
    {
        return self::$pricer ? (self::$pricer)($regionId, $vcpuHours, $memoryGbHours, $trigger, $viewer) : null;
    }
}
