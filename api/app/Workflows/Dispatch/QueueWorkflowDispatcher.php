<?php

namespace App\Workflows\Dispatch;

use App\Workflows\Jobs\ExecuteWorkflowRunJob;
use App\Workflows\Models\WorkflowRun;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class QueueWorkflowDispatcher implements WorkflowDispatcher
{
    public function dispatch(string $runId, ?DateTimeInterface $at = null): void
    {
        $maxDelay = (int) config('workflows.delayed_dispatch_max_seconds', 900);
        $delay = $at ? max(0, $at->getTimestamp() - now()->getTimestamp()) : 0;

        // Long sleeps are woken by workflows:tick instead of a far-future delayed job
        if ($delay > $maxDelay) {
            return;
        }

        DB::afterCommit(function () use ($runId, $delay) {
            WorkflowRun::whereKey($runId)->update(['dispatched_at' => now()]);

            $pending = ExecuteWorkflowRunJob::dispatch($runId);
            if ($delay > 0) {
                $pending->delay($delay);
            }
        });
    }
}
