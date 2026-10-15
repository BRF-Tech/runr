<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\RegistryImageLifecycleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Internal-registry build image retention for one project, dispatched by CheckBuildReadyJob
 * after it has marked a build ready and broadcast build.completed. Separate job: registry and
 * cluster calls here must neither delay the build notification nor run into the build check's
 * 30 s timeout. One pending run per project (every code save builds).
 */
class RetainRegistryImagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /** The project was deleted while queued: its images are removed by the delete itself */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->getKey();
    }

    public function handle(RegistryImageLifecycleService $registry): void
    {
        $project = $this->project->fresh(['knativeRegion']);

        if ($project) {
            $registry->retainForProject($project, config('registry.retain_versions'));
        }
    }
}
