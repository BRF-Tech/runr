<?php

namespace App\Jobs\GlobalProjects;

use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\GlobalProjects\GlobalReplicaDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A region left a global project (PUT /projects/{project}/global): its copy is removed there
 * (GlobalReplicaDeployer::removeRegion). Retried a few times; a copy that cannot be removed keeps
 * its row (status removing, with the reason) instead of being forgotten on the cluster.
 */
class RemoveGlobalReplicaJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Project $project, public string $projectRegionId) {}

    public function handle(GlobalReplicaDeployer $deployer): void
    {
        $row = ProjectRegion::query()->find($this->projectRegionId);
        if (! $row || $row->status !== ProjectRegion::STATUS_REMOVING) {
            return;
        }

        if (! $deployer->removeRegion($this->project, $row) && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 120);
        }
    }
}
