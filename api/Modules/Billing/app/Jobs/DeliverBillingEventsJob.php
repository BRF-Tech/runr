<?php

namespace Modules\Billing\Jobs;

use App\Services\Bindings\BindingTenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\BillingSettings;

/**
 * POSTs due billing events to the billing webhook (docs/billing.md#webhook-events): dispatched
 * when an event is recorded (for the organization it was recorded in — the queue keeps the
 * tenant) and every minute for every organization (retries).
 */
class DeliverBillingEventsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public bool $currentOnly = false) {}

    public function handle(BillingEvents $events, BillingSettings $settings): void
    {
        if ($this->currentOnly) {
            $events->deliverPending();

            return;
        }

        BindingTenancy::eachContext(function () use ($events, $settings) {
            $settings->flush();
            try {
                $events->deliverPending();
            } catch (\Throwable $e) {
                Log::warning('Billing event delivery run failed', ['tenant' => BindingTenancy::tenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        });
    }
}
