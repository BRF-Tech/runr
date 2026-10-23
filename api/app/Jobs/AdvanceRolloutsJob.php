<?php

namespace App\Jobs;

use App\Services\Observability\TenantScope;
use App\Services\Rollouts\RolloutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every minute: the safety net of the blue-green / canary rollouts
 * (RolloutService::advanceOverdue) — a check whose AdvanceRolloutJob was lost, and rollouts whose
 * deployments ended — in the central schema and in every tenant's.
 */
class AdvanceRolloutsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function handle(RolloutService $rollouts): void
    {
        $looked = 0;
        TenantScope::each(function () use ($rollouts, &$looked) {
            $looked += $rollouts->advanceOverdue();
        });

        if ($looked > 0) {
            Log::debug('Rollouts looked at', ['count' => $looked]);
        }
    }
}
