<?php

namespace App\Services\GitDeploy;

use App\Events\DeploymentCreated;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentScope;
use App\Services\Environments\RegionImages;
use App\Services\Projects\LiveDeployments;
use App\Services\Residency\RegionNotAllowedException;
use App\Services\Residency\RegionPolicy;
use App\Services\Rollouts\RolloutService;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use App\Support\Rbac\EnvVisibility;
use App\Support\SchemaColumns;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Starts a production deployment of a version the way POST /projects/{project}/deploy does:
 * the same checks (EOL runtime, pruned / unbuilt version, inactive region, data residency and
 * the supply-chain policy gate — DeployChecks — and ProjectAdmission: not stopped, module guards),
 * every live deployment of the project goes to shutting_down (LiveDeployments::supersede) —
 * but the one that serves while a blue-green / canary rollout brings the new one in
 * (RolloutService, the project's deploy strategy) — DeploymentCreated runs the Knative deploy
 * (ProcessDeployment) and the UI hears deployment.created (without env values, like the deploy
 * endpoint).
 *
 * An environment of the project (docs/environments.md) is deployed the same way: the checks see
 * the environment's view (its region, its config — DeployTarget), only that environment's live
 * deployments are replaced. A built version whose image is in another registry than the one of the
 * environment's region is built into that registry first (RegionImages): the new deployment waits,
 * pending, and the environment's active one keeps serving until it is ready. A rollback that
 * restores the config an earlier deployment ran with passes that snapshot ($restoredConfig,
 * docs/versioning.md#rollback).
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
     * @param  array{runtime_digest?: string|null, runtime_patch_id?: string|null}  $runtime  the runtime
     *                                                                                        image the deployment must run (a runtime patch
     *                                                                                        migration or its rollback, RuntimePatchDeployer);
     *                                                                                        empty: resolved when it is applied
     * @param  string|null  $strategy  direct / blue_green / canary for this deploy; null: the project's
     * @param  ProjectEnvironment|null  $environment  null (or the primary): the project's own workload
     * @param  array<string, mixed>|null  $restoredConfig  a config snapshot the deployment runs instead of the current config
     *
     * @throws DeployPendingException the version's security checks are still running
     * @throws ImportException the version cannot be deployed (reason in the message)
     * @throws DeployCancelledException $claim said no
     */
    public function start(Project $project, Version $version, string $deployedBy, ?string $initiatorId = null, ?array $deployConfig = null, ?\Closure $claim = null, array $runtime = [], ?string $strategy = null, ?ProjectEnvironment $environment = null, ?array $restoredConfig = null): Deployment
    {
        $project = EnvironmentScope::base($project);
        $environment = $environment !== null && ! $environment->is_primary ? $environment : null;
        $target = DeployTarget::environment($project, $environment, $version);
        $environmentId = EnvironmentScope::idOf($target);

        if ($project->isRuntimeEol()) {
            throw new ImportException(__('api.project.eol_deploy'));
        }
        if ($version->build_status === Version::STATUS_PRUNED) {
            throw new ImportException(__('api.project.version_pruned', ['version' => $version->number]));
        }
        if ($version->build_status !== 'ready') {
            throw new ImportException(__('api.project.version_not_ready', ['status' => $version->build_status]));
        }

        $region = $target->knativeRegion;
        if ($region && ! $region->is_active) {
            $statusMsg = $region->is_maintenance ? __('api.project.region_maintenance') : __('api.project.region_inactive');
            throw new ImportException(__('api.project.region_status', ['status' => $statusMsg]));
        }

        // Data residency of the region it runs in, the supply-chain gate
        DeployChecks::assert($target, $version);

        // A stopped project (or one a module's guard refuses, e.g. a fixed-price region without an
        // approved allocation) is not deployed: the push or webhook reports the reason
        try {
            ProjectAdmission::assertDeployable($target, DeployIntent::production($deployConfig, $environmentId));
        } catch (HttpResponseException $e) {
            throw new ImportException(ProjectAdmission::messageOf($e), ProjectAdmission::codeOf($e));
        }

        /** @var Deployment $deployment */
        $deployment = $project->deployments()->make([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => 'run-'.$target->slug.'-v'.$version->number,
            'deployed_by' => mb_substr($deployedBy, 0, 250),
            'deploy_config' => $deployConfig ?: null,
        ]);
        foreach (array_intersect_key($runtime, array_flip(['runtime_digest', 'runtime_patch_id'])) as $column => $value) {
            if (SchemaColumns::has($deployment, $column)) {
                $deployment->setAttribute($column, $value);
            }
        }
        if ($environmentId !== null) {
            $deployment->setAttribute('environment_id', $environmentId);
        }
        if ($restoredConfig !== null) {
            $deployment->forceFill(['config_snapshot' => $restoredConfig, 'config_source' => Deployment::CONFIG_RESTORED]);
        }
        $deployment->setAttribute($deployment->getKeyName(), $deployment->newUniqueId());
        if ($claim !== null && ! $claim((string) $deployment->getKey())) {
            throw new DeployCancelledException("The deploy of {$version->label} was cancelled before it started.");
        }

        // Blue-green / canary (the deploy strategy of the workload — the project's own, or the
        // environment's, which is the project's unless it sets its own — or $strategy for this
        // deploy): the deployment that serves stays live while the new one rolls out
        // (RolloutService::plan, on the environment's view for an environment)
        $rollouts = app(RolloutService::class);
        $workload = $environmentId === null ? $project : $target;
        $plan = $rollouts->plan($workload, $strategy);
        $rollouts->supersede($workload, $deployedBy);

        // Every other live deployment of the environment (a pending one too) goes to shutting_down:
        // only the new one goes live. A version that is built into the environment region's
        // registry first (RegionImages) keeps the environment's active deployment serving until the
        // new one is ready (LiveDeployments::activate() retires it then)
        $keep = $plan['stable'] ?? null;
        if ($environmentId !== null && app(RegionImages::class)->waits($target, $version)) {
            $keep = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), $environmentId)
                ->where('status', 'active')->latest('created_at')->first();
        }
        app(LiveDeployments::class)->supersede($project, $keep, $environmentId);

        $deployment->save();
        if ($plan) {
            $rollouts->open($plan, $deployment, $workload, $version, mb_substr($deployedBy, 0, 250));
        }

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
