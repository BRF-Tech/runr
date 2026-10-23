<?php

namespace App\Jobs\Rightsizing;

use App\Services\Bindings\BindingTenancy;
use App\Services\Metering\UsageAggregator;
use App\Services\Rightsizing\PeakCollector;
use App\Services\Rightsizing\RightsizingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Rightsizing (docs/monitoring.md#rightsizing), daily and in the central context and every
 * tenant's: the days the window misses are read from the regions' Prometheus (PeakCollector —
 * yesterday on a normal night, the whole window after an upgrade or a missed night), peaks past
 * their retention go, and every running project's recommendation is calculated again
 * (RightsizingService::refresh). `php artisan rightsizing:run` does the same on demand.
 */
class RightsizingJob implements ShouldQueue
{
    use Queueable;

    /** A failed night is caught up by the next one (the missing days are read then). */
    public int $tries = 1;

    /** Below the queue's retry_after (1900 s): one Prometheus query per project and missing day. */
    public int $timeout = 1800;

    public function __construct(public ?string $at = null) {}

    public function handle(PeakCollector $collector, RightsizingService $rightsizing): void
    {
        if (! config('rightsizing.enabled', true)) {
            return;
        }

        $today = $this->at !== null ? Carbon::parse($this->at) : now();

        // One organization's failure must not stop the others
        BindingTenancy::eachContext(function () use ($collector, $rightsizing, $today) {
            try {
                $collector->collect($today);
                $collector->prune($today);
                $rightsizing->refresh($today);
            } catch (\Throwable $e) {
                Log::error('Rightsizing failed', ['tenant' => UsageAggregator::tenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        });
    }
}
