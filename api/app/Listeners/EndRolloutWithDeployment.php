<?php

namespace App\Listeners;

use App\Events\DeploymentStatusChanged;
use App\Services\Rollouts\RolloutService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A deployment of a blue-green / canary rollout ended outside of the rollout — the project was
 * stopped, its status set by hand, its teardown failed: the rollout ends too, and the traffic goes
 * to what still runs (RolloutService::deploymentEnded). The rollout's own aborts, promotions and
 * releases end deployments as well; the rollout has ended by then and nothing happens.
 */
class EndRolloutWithDeployment implements ShouldQueue
{
    public int $tries = 2;

    public array $backoff = [15];

    public int $timeout = 60;

    /** A deployment deleted meanwhile: its rollout is looked at by the minute safety net */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private RolloutService $rollouts,
    ) {}

    public function handle(DeploymentStatusChanged $event): void
    {
        if (! in_array($event->newStatus, ['shutting_down', 'shut_down', 'failed'], true)) {
            return;
        }

        $this->rollouts->deploymentEnded($event->deployment);
    }
}
