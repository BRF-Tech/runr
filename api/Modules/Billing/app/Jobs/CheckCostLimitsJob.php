<?php

namespace Modules\Billing\Jobs;

use App\Services\Bindings\BindingTenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Services\BillingSettings;
use Modules\Billing\Services\CostLimits;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Support\BillingContext;

/**
 * Every 15 minutes (billing-cost-limits, docs/billing.md#monthly-cost-limit), after metering
 * (usage-aggregation, every 5 minutes) brought the month's usage up to date: in every
 * organization each project's monthly cost limit is compared with the month so far — the warning
 * at 80 %, the notice (and, if chosen, the stop) at 100 % (CostLimits::evaluate()). One
 * organization's failure does not stop the others; one run at a time.
 */
class CheckCostLimitsJob implements ShouldQueue
{
    use Queueable;

    public const LOCK = 'billing:cost-limits';

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(CostLimits $limits, BillingSettings $settings, PriceBook $prices, Regions $regions, RegionTypes $types): void
    {
        $lock = Cache::lock(self::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            Log::info('Billing: cost limit check skipped, another run holds the lock');

            return;
        }

        try {
            BindingTenancy::eachContext(function () use ($limits, $settings, $prices, $regions, $types) {
                $settings->flush();
                $prices->flush();
                $regions->flush();
                $types->flush();

                try {
                    $limits->evaluateAll();
                } catch (\Throwable $e) {
                    Log::error('Billing: checking cost limits failed', ['tenant' => BillingContext::tenantKey(), 'error' => $e->getMessage()]);
                    report($e);
                }
            });
        } finally {
            $lock->release();
        }
    }
}
