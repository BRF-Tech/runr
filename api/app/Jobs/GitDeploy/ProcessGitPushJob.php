<?php

namespace App\Jobs\GitDeploy;

use App\Models\Project;
use App\Models\User;
use App\Services\GitDeploy\GitDeployService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * A push to the RunR remote updated a branch: import the deploy branch's new head as a version
 * and deploy it (GitDeployService::remotePush). Dispatched by the smart-HTTP endpoint after
 * git-receive-pack finished; runs in the organization's tenant context (queue tenancy).
 */
class ProcessGitPushJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [5, 30];

    public function __construct(
        public string $projectId,
        public string $ref,
        public string $before,
        public string $after,
        public ?string $pusherId = null,
    ) {}

    /** One import per project at a time, in push order. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('git-push:'.$this->projectId))->releaseAfter(10)->expireAfter(600)];
    }

    public function handle(GitDeployService $deploys): void
    {
        $project = Project::find($this->projectId);
        if (! $project) {
            return;
        }

        $pusher = $this->pusherId ? User::find($this->pusherId) : null;

        $deploys->remotePush($project, $this->ref, $this->before, $this->after, $pusher);
    }
}
