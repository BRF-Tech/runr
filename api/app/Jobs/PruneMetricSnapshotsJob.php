<?php

namespace App\Jobs;

use App\Services\GitDeploy\TenantContext;
use App\Services\MetricsPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled (daily): metric snapshots past the retention (MetricsPersistenceService::cleanup,
 * 90 days; the daily ones stay) are removed — in the central schema and, in a multi-tenant
 * install, in every tenant's schema, which has a metric_snapshots table of its own.
 */
class PruneMetricSnapshotsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(MetricsPersistenceService $metrics): void
    {
        $deleted = $metrics->cleanup();

        if (TenantContext::enabled()) {
            TenantContext::each(function () use ($metrics, &$deleted) {
                $deleted += $metrics->cleanup();
            });
        }

        if ($deleted > 0) {
            Log::info('Expired metric snapshots removed', ['count' => $deleted]);
        }
    }
}
