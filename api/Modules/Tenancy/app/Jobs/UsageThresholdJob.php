<?php

namespace Modules\Tenancy\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Services\Billing\UsageThresholds;

/**
 * Hourly: billing.usage.threshold events for usage amounts and plan limits reached this month
 * (UsageThresholds, docs/billing.md).
 */
class UsageThresholdJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(UsageThresholds $thresholds): void
    {
        $recorded = $thresholds->check();

        if ($recorded > 0) {
            Log::info('Usage thresholds reached', ['events' => $recorded]);
        }
    }
}
