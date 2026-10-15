<?php

namespace App\Jobs;

use App\Models\ImageSecurityReport;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Creates the supply-chain Job of a pending report in its region (SupplyChainPipeline::submit).
 * Queued from the build-ready observers, a re-scan or the scheduler; in tenant context it runs
 * in that tenant (the report lives in the tenant's schema).
 */
class StartSupplyChainJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    /** The version (project) was deleted while the run was queued */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public ImageSecurityReport $report) {}

    public function handle(SupplyChainPipeline $pipeline): void
    {
        $report = $this->report->fresh();
        if (! $report || $report->status !== 'pending') {
            return;
        }

        $pipeline->submit($report);
    }

    public function failed(?\Throwable $e): void
    {
        $report = $this->report->fresh();
        if ($report && in_array($report->status, ['pending', 'running'], true)) {
            app(SupplyChainPipeline::class)->fail($report, 'Supply-chain run could not start: '.($e?->getMessage() ?? 'unknown error'));
        }
    }
}
