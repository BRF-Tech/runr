<?php

namespace App\Services\GitDeploy;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Support\Rbac\EnvVisibility;

/**
 * Starts a production deployment of a version the way POST /projects/{project}/deploy does:
 * the same checks (EOL runtime, pruned / unbuilt version, inactive region, data residency and
 * the supply-chain policy gate — DeployChecks), the active deployment goes to shutting_down,
 * DeploymentCreated runs the Knative deploy (ProcessDeployment) and the UI hears
 * deployment.created (without env values, like the deploy endpoint).
 */
class DeploymentStarter
{
    use BroadcastsModelEvents;

    /**
     * @throws DeployPendingException the version's security checks are still running
     * @throws ImportException the version cannot be deployed (reason in the message)
     */
    public function start(Project $project, Version $version, string $deployedBy, ?string $initiatorId = null): Deployment
    {
        if ($project->isRuntimeEol()) {
            throw new ImportException(__('api.project.eol_deploy'));
        }
        if ($version->build_status === Version::STATUS_PRUNED) {
            throw new ImportException(__('api.project.version_pruned', ['version' => $version->number]));
        }
        if ($version->build_status !== 'ready') {
            throw new ImportException(__('api.project.version_not_ready', ['status' => $version->build_status]));
        }

        $region = $project->knativeRegion;
        if ($region && ! $region->is_active) {
            $statusMsg = $region->is_maintenance ? __('api.project.region_maintenance') : __('api.project.region_inactive');
            throw new ImportException(__('api.project.region_status', ['status' => $statusMsg]));
        }

        DeployChecks::assert($project, $version);

        $previous = $project->deployments()->where('status', 'active')->first();
        if ($previous) {
            $previous->update(['status' => 'shutting_down']);
            event(new DeploymentStatusChanged($previous, 'active', 'shutting_down'));
        }

        $deployment = $project->deployments()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => mb_substr($deployedBy, 0, 250),
        ]);

        event(new DeploymentCreated($deployment, $project, $version, $initiatorId));

        $this->broadcastModel('deployment.created', [
            'project_id' => $project->id,
            'deployment' => EnvVisibility::withoutEnv(fn () => (new DeploymentResource($deployment->load('version')))->resolve()),
        ]);

        return $deployment;
    }
}
