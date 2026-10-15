<?php

namespace Modules\Tenancy\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Services\Billing\ChargeService;

/**
 * Daily (docs/billing.md): the month's capacity-deal and usage charges exist, and every open charge
 * of a month that has ended is closed — final amounts, credit drawn, one billing.period.closed
 * event per tenant and month. Idempotent; a missed day is caught up on the next run.
 */
class ChargeScheduleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(ChargeService $charges): void
    {
        $created = $charges->ensureCharges();
        $closed = $charges->closeEndedPeriods();

        if ($created + $closed > 0) {
            Log::info('Charges scheduled', ['created' => $created, 'closed' => $closed]);
        }
    }
}
