<?php

namespace App\Jobs\GlobalProjects;

use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\GlobalProjects\GlobalReplicaDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of a global project's rollout to one region (GlobalReplicaDeployer::step), queued again
 * after the delay the step asks for. The token ties the steps to their rollout: a newer rollout of
 * the region (a newer deploy) has its own, and the steps of the older one stop at their next turn.
 */
class RolloutGlobalReplicaJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    /** The project was deleted while the step was queued */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Project $project,
        public string $projectRegionId,
        public string $token,
    ) {}

    public function handle(GlobalReplicaDeployer $deployer): void
    {
        $row = ProjectRegion::query()->find($this->projectRegionId);
        if (! $row) {
            return;
        }

        $project = $this->project->fresh() ?? $this->project;
        $delay = $deployer->step($project, $row, $this->token);

        if ($delay !== null) {
            self::dispatch($project, $this->projectRegionId, $this->token)->delay(now()->addSeconds($delay));
        }
    }
}
