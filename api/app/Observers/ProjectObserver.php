<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\ProjectCleanupService;
use App\Services\ProjectDomainService;
use App\Services\ResourceThresholdService;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    public function creating(Project $project): void
    {
        $project->slug = Project::slugFor($project->name);
    }

    public function updating(Project $project): void
    {
        if ($project->isDirty('name')) {
            $project->slug = Project::slugFor($project->name);
        }

        // A moved project runs nowhere until its next deploy: region capacity counts it with the
        // new region's RuntimeClass mapping until then (ResourceReservation::deployedClassOf)
        if ($project->isDirty('knative_region_id') && $project->getAttribute('deployed_runtime_class') !== null) {
            $project->setAttribute('deployed_runtime_class', null);
        }
    }

    public function created(Project $project): void
    {
        $this->dispatchThresholdCheck($project);
    }

    public function updated(Project $project): void
    {
        if ($project->isDirty('knative_region_id')) {
            $this->dispatchThresholdCheck($project);
            $this->moveDomains($project);
        }
    }

    /**
     * Custom domains, K8s teardown, private connections, git/build directories, registry images.
     * A failure is logged and the row is deleted anyway; the tenant deletion flow calls
     * ProjectCleanupService itself and counts them.
     */
    public function deleting(Project $project): void
    {
        app(ProjectCleanupService::class)->cleanup($project);
    }

    /**
     * The project left its region — its custom domains leave it too and wait
     * for a new verification (their DNS target changed).
     */
    private function moveDomains(Project $project): void
    {
        try {
            app(ProjectDomainService::class)->regionChanged($project, $project->getOriginal('knative_region_id'));
        } catch (\Throwable $e) {
            Log::warning('Custom domain cleanup failed after region change', [
                'project' => $project->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchThresholdCheck(Project $project): void
    {
        $region = $project->knativeRegion;
        if (! $region) {
            return;
        }

        dispatch(function () use ($region) {
            app(ResourceThresholdService::class)->checkRegion($region);
        })->afterResponse();
    }
}
