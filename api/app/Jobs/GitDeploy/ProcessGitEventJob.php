<?php

namespace App\Jobs\GitDeploy;

use App\Models\Git\GitRepoLink;
use App\Models\Project;
use App\Services\GitDeploy\BranchRules;
use App\Services\GitDeploy\GitDeployService;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\Providers\GitEvent;
use App\Services\GitDeploy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * A verified provider webhook for a connected project: the branch rules decide (BranchRules) —
 * production deploy, preview create/update/delete, or nothing.
 */
class ProcessGitEventJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [30];

    public function __construct(
        public string $linkId,
        public array $event,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('git-event:'.$this->linkId))->releaseAfter(10)->expireAfter(600)];
    }

    public function handle(GitDeployService $deploys, PreviewService $previews): void
    {
        $link = GitRepoLink::with('provider')->find($this->linkId);
        if (! $link) {
            return;
        }

        TenantContext::run($link->tenant_id, function () use ($link, $deploys, $previews) {
            $project = Project::find($link->project_id);
            if (! $project) {
                return;
            }

            $event = GitEvent::fromArray($this->event);

            match (BranchRules::decide($project, $event)) {
                BranchRules::PRODUCTION => $deploys->providerPush($project, $link, $event),
                BranchRules::PREVIEW => $previews->handle($project, $link, $event),
                default => null,
            };
        });
    }
}
