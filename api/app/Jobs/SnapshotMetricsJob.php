<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Services\GitDeploy\TenantContext;
use App\Services\MetricsPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled (every minute): the live metrics of every active deployment become a 1-minute
 * metric snapshot — in the central schema and, in a multi-tenant install, in every tenant's
 * schema, where tenant projects and their metric_snapshots live.
 */
class SnapshotMetricsJob implements ShouldQueue
{
    use Queueable;

    public function handle(MetricsPersistenceService $service): void
    {
        $this->snapshotSchema($service);

        if (TenantContext::enabled()) {
            TenantContext::each(fn () => $this->snapshotSchema($service));
        }
    }

    /**
     * The current schema's active, real regions with cluster access. Filtered on the model: a
     * tenant's copy of a platform region stores no kubeconfig and reads it from central.
     */
    private function snapshotSchema(MetricsPersistenceService $service): void
    {
        $regions = KnativeRegion::where('is_active', true)
            ->where('is_demo', false)
            ->get()
            ->filter(fn (KnativeRegion $region) => (bool) $region->kubeconfig);

        foreach ($regions as $region) {
            $service->snapshotAllActive($region);
        }
    }
}
