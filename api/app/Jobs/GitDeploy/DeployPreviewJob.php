<?php

namespace App\Jobs\GitDeploy;

use App\Models\Git\PreviewEnvironment;
use App\Services\GitDeploy\PreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Apply a preview's version to the region (PreviewService::deploy). */
class DeployPreviewJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** @param  int  $waited  checks so far while the preview's runtime image builds in the region */
    public function __construct(public string $previewId, public int $waited = 0) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('git-preview:'.$this->previewId))->releaseAfter(5)->expireAfter(300)];
    }

    public function handle(PreviewService $previews): void
    {
        if ($preview = PreviewEnvironment::find($this->previewId)) {
            $previews->deploy($preview, $this->waited);
        }
    }
}
