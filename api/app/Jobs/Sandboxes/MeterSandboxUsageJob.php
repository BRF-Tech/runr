<?php

namespace App\Jobs\Sandboxes;

use App\Services\Sandboxes\SandboxUsageAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sandbox usage metering every five minutes (SandboxUsageAggregator): today's records, and
 * yesterday's during the first hour of a day. Each run re-aggregates the whole day, so a failed
 * run is caught up by the next one.
 */
class MeterSandboxUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function handle(SandboxUsageAggregator $aggregator): void
    {
        $aggregator->aggregateRecent();
    }
}
