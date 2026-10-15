<?php

namespace App\Jobs\GitDeploy;

use App\Models\Git\GitDeploy;
use App\Models\Git\PreviewEnvironment;
use App\Services\GitDeploy\StatusReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Report a git deploy (commit status, GitHub deployment) and/or a preview (PR comment) to the
 * provider. Provider errors are logged by the reporter, never retried into a storm.
 */
class ReportGitStatusJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public ?string $gitDeployId,
        public ?string $previewId = null,
    ) {}

    public function handle(StatusReporter $reporter): void
    {
        if ($this->gitDeployId && ($deploy = GitDeploy::with(['project.knativeRegion', 'preview'])->find($this->gitDeployId))) {
            $reporter->deploy($deploy);
        }

        if ($this->previewId && ($preview = PreviewEnvironment::with('project')->find($this->previewId))) {
            $reporter->previewComment($preview);
        }
    }
}
