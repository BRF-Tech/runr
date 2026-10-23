<?php

namespace App\Jobs\Environments;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use App\Services\KnativeDeployService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * An environment's deployment that waited for its image (RegionImages: its version built into the
 * region's registry, or a runtime image built in the region) goes on: the deploy runs again from
 * the start — every check of KnativeDeployService::deploy, then the apply. A deployment that is no
 * longer pending (replaced, stopped) is left alone. $attempt counts the waits for a runtime image.
 */
class ResumeEnvironmentDeployJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(
        public string $deploymentId,
        public int $attempt = 0,
    ) {}

    public function handle(KnativeDeployService $deployer): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);
        if (! $deployment || $deployment->status !== 'pending' || $deployment->isPrimary()) {
            return;
        }

        $project = Project::query()->find($deployment->project_id);
        $version = Version::query()->find($deployment->version_id);
        if (! $project || ! $version) {
            return;
        }

        $deployer->deploy($project->load(['knativeRegion', 'xaasConfig', 'faasConfig', 'containerConfig']), $version, $deployment, null, $this->attempt);
    }

    public function failed(?\Throwable $e): void
    {
        Deployment::query()->find($this->deploymentId)?->transition(['pending', 'running'], [
            'status' => 'failed',
            'knative_ready' => false,
            'knative_message' => mb_substr('Deploy did not complete: '.($e?->getMessage() ?? 'unknown error'), 0, 255),
        ]);
    }
}
