<?php

namespace App\Jobs;

use App\Services\GitDeploy\TenantContext;
use App\Services\MetricsPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled (hourly): aging metric snapshots are folded into coarser ones
 * (MetricsPersistenceService::downsample) — in the central schema and in every tenant schema.
 */
class DownsampleMetricsJob implements ShouldQueue
{
    use Queueable;

    public function handle(MetricsPersistenceService $service): void
    {
        $service->downsample();

        if (TenantContext::enabled()) {
            TenantContext::each(fn () => $service->downsample());
        }
    }
}
