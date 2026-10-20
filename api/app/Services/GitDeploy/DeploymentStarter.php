<?php

namespace App\Services\GitDeploy;

use App\Events\DeploymentCreated;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\Projects\LiveDeployments;
use App\Services\Residency\RegionNotAllowedException;
use App\Services\Residency\RegionPolicy;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use App\Support\Rbac\EnvVisibility;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Starts a production deployment of a version the way POST /projects/{project}/deploy does:
 * the same checks (EOL runtime, pruned / unbuilt version, inactive region, data residency and
 * the supply-chain policy gate — DeployChecks — and ProjectAdmission: not stopped, module guards),
 * every live deployment of the project goes to shutting_down (LiveDeployments::supersede),
 * DeploymentCreated runs the Knative deploy (ProcessDeployment) and the UI hears
 * deployment.created (without env values, like the deploy endpoint).
 */
class DeploymentStarter
{
    use BroadcastsModelEvents;

    /**
     * @param  array<string, mixed>|null  $deployConfig  overrides of the project config for this
     *                                                   deployment (deploy_config; a resume keeps the ones of the deployment it restores)
     * @param  (\Closure(string): bool)|null  $claim  asked with the id of the deployment about to be
     *                                                created, once every check passed and before the
     *                                                project's live deployments are touched: false —
     *                                                the deploy was cancelled meanwhile — starts nothing
     *
     * @throws DeployPendingException the version's security checks are still running
     * @throws ImportException the version cannot be deployed (reason in the message)
     * @throws DeployCancelledException $claim said no
     */
    public function start(Project $project, Version $version, string $deployedBy, ?string $initiatorId = null, ?array $deployConfig = null, ?\Closure $claim = null): Deployment
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

        // A stopped project (or one a module's guard refuses, e.g. a fixed-price region without an
        // approved allocation) is not deployed: the push or webhook reports the reason
        try {
            ProjectAdmission::assertDeployable($project, DeployIntent::production($deployConfig));
        } catch (HttpResponseException $e) {
            throw new ImportException(ProjectAdmission::messageOf($e), ProjectAdmission::codeOf($e));
        }

        /** @var Deployment $deployment */
        $deployment = $project->deployments()->make([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => mb_substr($deployedBy, 0, 250),
            'deploy_config' => $deployConfig ?: null,
        ]);
        $deployment->setAttribute($deployment->getKeyName(), $deployment->newUniqueId());
        if ($claim !== null && ! $claim((string) $deployment->getKey())) {
            throw new DeployCancelledException("The deploy of {$version->label} was cancelled before it started.");
        }

        // Every live deployment (a pending one too) goes to shutting_down: only the new one goes live
        app(LiveDeployments::class)->supersede($project);

        $deployment->save();

        event(new DeploymentCreated($deployment, $project, $version, $initiatorId));

        $this->broadcastModel('deployment.created', [
            'project_id' => $project->id,
            'deployment' => EnvVisibility::withoutEnv(fn () => (new DeploymentResource($deployment->load('version')))->resolve()),
        ]);

        return $deployment;
    }

    /**
     * The checks of start() that do not depend on the version's image — an end-of-life runtime,
     * the region's status, data residency and ProjectAdmission — for a deploy that builds its
     * version again first (VersionRebuilds): the supply-chain gate runs on the new image when
     * the deploy starts.
     *
     * @param  array<string, mixed>|null  $deployConfig
     *
     * @throws ImportException the deploy would be refused (reason in the message)
     */
    public function assertBeforeBuild(Project $project, ?array $deployConfig = null): void
    {
        if ($project->isRuntimeEol()) {
            throw new ImportException(__('api.project.eol_deploy'));
        }

        $region = $project->knativeRegion;
        if ($region && ! $region->is_active) {
            $statusMsg = $region->is_maintenance ? __('api.project.region_maintenance') : __('api.project.region_inactive');
            throw new ImportException(__('api.project.region_status', ['status' => $statusMsg]));
        }

        try {
            app(RegionPolicy::class)->authorize($region, RegionPolicy::DEPLOY);
        } catch (RegionNotAllowedException $e) {
            throw new ImportException($e->getMessage());
        }

        try {
            ProjectAdmission::assertDeployable($project, DeployIntent::production($deployConfig));
        } catch (HttpResponseException $e) {
            throw new ImportException(ProjectAdmission::messageOf($e), ProjectAdmission::codeOf($e));
        }
    }
}
