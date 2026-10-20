<?php

namespace App\Jobs;

use App\Models\QueuedDeploy;
use App\Services\Projects\QueuedDeploys;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of a deploy that waits for its version's build (QueuedDeploys::process()): dispatched
 * when the build ends, when the version's supply-chain checks are final, and every
 * QueuedDeploys::WAIT_SECONDS while a runtime image builds in the region. Runs in the context of
 * the organization it was dispatched in (its projects live in its schema).
 */
class ProcessQueuedDeployJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public string $queuedDeployId) {}

    public function handle(QueuedDeploys $deploys): void
    {
        $queued = QueuedDeploy::query()->find($this->queuedDeployId);
        if ($queued) {
            $deploys->process($queued);
        }
    }
}
