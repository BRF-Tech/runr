<?php

namespace Modules\Tenancy\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Tenancy\Services\Billing\BillingEvents;

/**
 * POSTs due billing events to the billing webhook (docs/billing.md) — dispatched when an event is
 * recorded and every minute for retries. One at a time (unique), oldest event first.
 */
class DeliverBillingEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function handle(BillingEvents $events): void
    {
        $events->deliverPending();
    }
}
