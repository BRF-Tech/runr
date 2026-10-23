<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\Environments\EnvironmentManager;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\ProjectCleanupService;
use App\Services\ProjectDomainService;
use App\Services\ResourceThresholdService;
use App\Support\TenantAccess;
use App\Triggers\Cleanup\CdcSlotCleanup;
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
            $this->keepRunningEnvironments($project);
        }
        // a new name is a new internal name: the organization's allow lists follow
        if ($project->wasChanged('slug') && $project->getOriginal('slug')) {
            $this->internalCallerChanged($project, (string) $project->getOriginal('slug'), InternalNetwork::nameOf($project));
            // its cdc triggers read new default slots after the next deploy: the old ones go then
            try {
                app(CdcSlotCleanup::class)->projectRenamed($project, (string) $project->getOriginal('slug'));
            } catch (\Throwable $e) {
                Log::warning('CDC cleanup not scheduled', ['project' => $project->slug, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Environments that follow the project's region and run there keep their region: a move of
     * the project does not move what an environment runs (docs/environments.md#another-region).
     */
    private function keepRunningEnvironments(Project $project): void
    {
        try {
            app(EnvironmentManager::class)->projectMoved($project, $project->getOriginal('knative_region_id'));
        } catch (\Throwable $e) {
            Log::warning('Environments not pinned after the project moved', ['project' => $project->slug, 'error' => $e->getMessage()]);
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
        // its grants on the organization's allow lists go with it: a later project of the same
        // name does not inherit them
        $this->internalCallerChanged($project, (string) $project->slug, null);
    }

    private function internalCallerChanged(Project $project, string $slug, ?string $renamedTo): void
    {
        try {
            $old = InternalNetwork::nameFor($slug, TenantAccess::current()['slug'] ?? null);
            app(InternalNetworkService::class)->callerChanged($old, $renamedTo);
        } catch (\Throwable $e) {
            Log::warning('Internal network allow lists not updated', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }
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
