<?php

namespace Modules\Billing\Jobs;

use App\Services\Bindings\BindingTenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Services\BillingActivity;
use Modules\Billing\Services\BillingSettings;
use Modules\Billing\Services\BillService;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Services\TenantBillService;
use Modules\Billing\Support\BillingContext;

/**
 * Daily at 02:30 (billing-close, docs/billing.md#bills), after the last usage of the previous day
 * was metered: in every organization the bills whose days have ended are closed and the current
 * month's open bills created; centrally the platform → tenant bills likewise. One organization's
 * failure does not stop the others; a month missed closes on the next run.
 *
 * One run at a time: the job holds a cache lock (with its owner) for as long as it runs — a second
 * worker or a manual dispatch finds it taken and returns. Each bill is closed in a transaction on
 * its locked row (BillService::close()), so even two runs could not close a bill or record its
 * bill.closed event twice.
 */
class CloseBillsJob implements ShouldQueue
{
    use Queueable;

    public const LOCK = 'billing:close-bills';

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(BillService $bills, TenantBillService $tenantBills, BillingSettings $settings, PriceBook $prices, Regions $regions, RegionTypes $types, BillingActivity $activity): void
    {
        // Held past the job's timeout: a worker killed at the timeout leaves it to expire
        $lock = Cache::lock(self::LOCK, $this->timeout + 300);
        if (! $lock->get()) {
            Log::info('Billing: closing bills skipped, another run holds the lock');

            return;
        }

        try {
            $activity->touch();

            BindingTenancy::eachContext(function () use ($bills, $tenantBills, $settings, $prices, $regions, $types) {
                $settings->flush();
                $prices->flush();
                $regions->flush();
                $types->flush();

                try {
                    $bills->closeEndedPeriods();
                    $bills->ensureOpenBills();

                    if (! BillingContext::inTenant()) {
                        $tenantBills->closeEndedPeriods();
                        $tenantBills->ensureOpenBills();
                    }
                } catch (\Throwable $e) {
                    Log::error('Billing: closing bills failed', ['tenant' => BillingContext::tenantKey(), 'error' => $e->getMessage()]);
                    report($e);
                }
            });
        } finally {
            $lock->release();
        }
    }
}
