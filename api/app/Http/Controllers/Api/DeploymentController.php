<?php

namespace App\Http\Controllers\Api;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Http\Requests\Project\DeployProjectRequest;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\QueuedDeployResource;
use App\Mcp\Hosting\CallLogLines;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\QueuedDeploy;
use App\Models\Version;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentResolver;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\DeployPendingException;
use App\Services\GitDeploy\ImportException;
use App\Services\KnativeDeployService;
use App\Services\KnativeLogService;
use App\Services\KnativeMetricsService;
use App\Services\Projects\ConfigRestore;
use App\Services\Projects\LiveDeployments;
use App\Services\Projects\VersionRebuilds;
use App\Services\Residency\RegionPolicy;
use App\Services\Rollouts\DeployStrategy;
use App\Services\Rollouts\RolloutActionException;
use App\Services\Rollouts\RolloutService;
use App\Services\SupplyChain\DeployPolicyGate;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use App\Support\Rbac\EnvVisibility;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DeploymentController extends Controller
{
    use BroadcastsModelEvents;

    /**
     * Deployments of the project's own workload, newest first; ?environment= (a name or id) lists
     * one environment's (docs/environments.md), ?environment=all every environment's.
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $query = match (true) {
            $request->query('environment') === 'all' => $project->allDeployments(),
            $request->filled('environment') => DeployTarget::environment($project, EnvironmentResolver::resolve($project, $request->query('environment')))->deployments(),
            default => $project->deployments(),
        };
        $deployments = $query->with(['version', ...DeploymentResource::rolloutRelations()])->latest()->get();

        return DeploymentResource::collection($deployments);
    }

    // The deploy checks raise their refusals as exceptions (ProjectAdmission), which the OpenAPI
    // generator does not see: 409 project_stopped, 422 a deploy guard's code (fixed_allocation_*,
    // fixed_max_scale_required, docs/billing.md#errors) or a version / region / policy message
    //
    // environment (a name or id): deploy to one of the project's environments (docs/environments.md)
    // instead of its own workload. restore_config: run the config an earlier deployment of the
    // version recorded (restore_from_deployment_id, else the newest one in that environment) —
    // what a rollback does (docs/versioning.md#rollback).
    #[OpenApiResponse(409, 'The project is stopped', type: 'array{message: string, code: "project_stopped", reason: string|null, level: string|null}')]
    #[OpenApiResponse(422, 'A deploy check or the validation refused the deploy', type: 'array{message: string, code?: string, errors?: array<string, string[]>}')]
    public function deploy(DeployProjectRequest $request, Project $project): JsonResponse
    {
        if ($project->isRuntimeEol()) {
            abort(422, __('api.project.eol_deploy'));
        }

        $data = $request->validated();
        $environment = EnvironmentResolver::resolve($project, $data['environment'] ?? null);

        // An environment's deploy, or one with a restored config: the guarded deploy path
        if ($environment !== null || ! empty($data['restore_config'])) {
            /** @var Version $version */
            $version = $project->versions()->findOrFail($data['version_id']);
            $from = ! empty($data['restore_from_deployment_id'])
                ? Deployment::query()->where('project_id', $project->id)->findOrFail($data['restore_from_deployment_id'])
                : null;

            return $this->deployTo($request, $project, $version, $environment, $data['deploy_config'] ?? null, ! empty($data['restore_config']), $from, $data['strategy'] ?? null);
        }

        // Stopped projects (409 project_stopped) and module guards, e.g. billing's fixed
        // allocations — with the deploy's overrides (deploy_config)
        ProjectAdmission::assertDeployable($project, DeployIntent::production($data['deploy_config'] ?? null));

        /** @var Version $version */
        $version = $project->versions()->findOrFail($data['version_id']);

        if ($version->build_status === Version::STATUS_PRUNED) {
            abort(422, __('api.project.version_pruned', ['version' => $version->number]));
        }

        if ($version->build_status !== 'ready') {
            abort(422, __('api.project.version_not_ready', ['status' => $version->build_status]));
        }

        $region = $project->knativeRegion;
        if ($region && ! $region->is_active) {
            $statusMsg = $region->is_maintenance
                ? __('api.project.region_maintenance')
                : __('api.project.region_inactive');
            abort(422, __('api.project.region_status', ['status' => $statusMsg]));
        }

        // A version built into a registry the region's cluster does not pull from (built before
        // the region moved to another cluster): built again into the region's and deployed when
        // ready — through every deploy check then, the policy gate on the new image's scan
        if (VersionRebuilds::needed($project, $version)) {
            app(RegionPolicy::class)->authorize($region, RegionPolicy::DEPLOY);

            return $this->deployWhenRebuilt($request, $project, $version, $data['deploy_config'] ?? null);
        }

        // Data residency + supply-chain policy gate (docs/supply-chain.md); throws 422 when blocked
        $policy = app(DeployPolicyGate::class)->authorize($request, $project, $version);

        // Blue-green / canary (the project's deploy strategy, or `strategy` for this deploy): the
        // deployment that serves stays live while the new one rolls out (RolloutService)
        $rollouts = app(RolloutService::class);
        $plan = $rollouts->plan($project, $data['strategy'] ?? null);
        $rollouts->supersede($project, $request->user()->username);

        // Every other live deployment (a pending one too) goes to shutting_down: only the new one goes live
        app(LiveDeployments::class)->supersede($project, $plan['stable'] ?? null);

        /** @var Deployment $deployment */
        $deployment = $project->deployments()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => $request->user()->username,
            'deploy_config' => $data['deploy_config'] ?? null,
        ]);

        if ($plan) {
            $rollouts->open($plan, $deployment, $project, $version, $request->user()->username);
        }

        app(DeployPolicyGate::class)->recordFor($policy, $deployment);

        event(new DeploymentCreated($deployment, $project, $version, auth()->id()));

        $resource = new DeploymentResource($deployment->load(['version', ...DeploymentResource::rolloutRelations()]));
        $this->broadcastModel('deployment.created', [
            'project_id' => $project->id,
            'deployment' => EnvVisibility::withoutEnv(fn () => $resource->resolve()),
        ]);

        return response()->json($resource, 202);
    }

    /**
     * POST /projects/{project}/deployments/{deployment}/rollback — deploy the deployment's version
     * again in its environment, with the config it ran with (its config_snapshot and overrides),
     * unless keep_current_config: then with the config of now (docs/versioning.md#rollback).
     *
     * The deployment, or (a version built again for the region first) the queued deploy; the
     * refusals of deployTo() come with a computed status, which the generator cannot follow.
     *
     * @response JsonResponse<DeploymentResource|array{message: string, queued_deploy: QueuedDeployResource}, 202>
     */
    #[OpenApiResponse(409, 'The project is stopped', type: 'array{message: string, code: "project_stopped"}')]
    #[OpenApiResponse(422, 'A deploy check refused the rollback', type: 'array{message: string, code?: string}')]
    public function rollback(Request $request, Project $project, Deployment $deployment): JsonResponse
    {
        $data = $request->validate([
            'keep_current_config' => ['nullable', 'boolean'],
            // blue-green / canary for this rollback (default the strategy of the deployment's environment; docs/deploy-strategies.md)
            'strategy' => ['nullable', 'string', Rule::in(array_merge(DeployStrategy::NAMES, ['blue-green', 'bluegreen']))],
        ]);
        if ((string) $deployment->project_id !== (string) $project->id) {
            abort(404);
        }

        $version = Version::query()->where('project_id', $project->id)->find($deployment->version_id);
        if (! $version) {
            abort(422, __('environments.rollback_version_gone'));
        }
        $environment = $deployment->isPrimary() ? null : ProjectEnvironment::query()->find($deployment->environment_id);
        $keep = (bool) ($data['keep_current_config'] ?? false);

        return $this->deployTo(
            $request,
            $project,
            $version,
            $environment,
            $keep ? null : (is_array($deployment->deploy_config) && $deployment->deploy_config !== [] ? $deployment->deploy_config : null),
            ! $keep,
            $deployment,
            $data['strategy'] ?? null,
        );
    }

    /**
     * A deploy through DeploymentStarter — every check of a git deploy, answered as the API does —
     * to an environment and / or with a restored config. A version that has to be built again for
     * the project's region waits for the build (the project's own workload only).
     *
     * @param  array<string, mixed>|null  $deployConfig
     * @param  string|null  $strategy  direct / blue_green / canary for this deploy (null: the environment's —
     *                                 the project's unless it sets its own)
     */
    private function deployTo(Request $request, Project $project, Version $version, ?ProjectEnvironment $environment, ?array $deployConfig, bool $restore, ?Deployment $from = null, ?string $strategy = null): JsonResponse
    {
        // Nothing recorded for the version (deployed before snapshots, never deployed): the current
        // config — the deployment says so (config_source "current")
        $restored = null;
        if ($restore) {
            $snapshot = app(ConfigRestore::class)->snapshotFor($project, $environment, $version, $from);
            $restored = $snapshot !== null ? ConfigRestore::restored($project, $snapshot) : null;
        }

        $target = DeployTarget::environment($project, $environment, $version);
        if ($environment === null && VersionRebuilds::needed($target, $version)) {
            app(RegionPolicy::class)->authorize($project->knativeRegion, RegionPolicy::DEPLOY);

            return $this->deployWhenRebuilt($request, $project, $version, $deployConfig, $restored);
        }

        try {
            $deployment = app(DeploymentStarter::class)->start(
                $project->loadMissing('knativeRegion'),
                $version,
                (string) $request->user()->username,
                auth()->id() !== null ? (string) auth()->id() : null,
                $deployConfig,
                strategy: $strategy,
                environment: $environment,
                restoredConfig: $restored,
            );
        } catch (ImportException $e) {
            // A DeployPendingException too: the version's security checks are still running
            $code = $e instanceof DeployPendingException ? 'deploy_pending' : $e->errorCode;

            return response()->json(array_filter(['message' => $e->getMessage(), 'code' => $code]), $code === 'project_stopped' ? 409 : 422);
        }

        return response()->json(new DeploymentResource($deployment->load('version')), 202);
    }

    /**
     * The deploy of a version that is built again first (VersionRebuilds): 202 with the queued
     * deploy instead of a deployment — GET queued-deploys/{id} follows it to its deployment.
     *
     * @param  array<string, mixed>|null  $restored  the config a rollback restores
     */
    private function deployWhenRebuilt(Request $request, Project $project, Version $version, ?array $deployConfig, ?array $restored = null): JsonResponse
    {
        $queued = app(VersionRebuilds::class)->queue($project, $version, (string) $request->user()->username, [
            'deploy_config' => $deployConfig,
            'initiator_id' => auth()->id() !== null ? (string) auth()->id() : null,
            'config_snapshot' => $restored,
        ]);

        return response()->json([
            'message' => VersionRebuilds::message($version),
            'queued_deploy' => new QueuedDeployResource($queued),
        ], 202);
    }

    public function show(Project $project, Deployment $deployment): DeploymentResource
    {
        return new DeploymentResource($deployment->load(['version', ...DeploymentResource::rolloutRelations()]));
    }

    /**
     * A deploy that waits for its version's build — the answer of a deploy whose version is built
     * again for the region's registry: status waiting, starting, deployed (deployment_id),
     * failed or cancelled, with what happened in message. Never answered from the response cache:
     * a build and a deploy change it, not the project.
     */
    #[OpenApiResponse(200, 'The queued deploy', type: 'array{data: array{id: string, project_id: string, version_id: string, version: int|null, status: "waiting"|"starting"|"deployed"|"failed"|"cancelled", reason: string|null, message: string|null, deployment_id: string|null, created_at: string|null, updated_at: string|null}}')]
    public function queued(Request $request, Project $project, string $queuedDeploy): JsonResponse
    {
        $queued = QueuedDeploy::query()->where('project_id', $project->id)->findOrFail($queuedDeploy);

        return (new QueuedDeployResource($queued))->response($request)->header('Cache-Control', 'no-store, private');
    }

    public function logs(Project $project, Deployment $deployment): JsonResponse
    {
        $deployment->load('version');
        $sinceMs = request()->integer('since') ?: null;
        // An environment's deployment: its pods, in its region
        $data = app(KnativeLogService::class)->getDeploymentLogs(DeployTarget::project($project, $deployment), $deployment, $sinceMs);
        // Calls through the MCP gateway of a hosted MCP server (pod "mcp-gateway", docs/mcp.md)
        if (in_array($deployment->status, ['pending', 'running', 'active'], true)) {
            $data = CallLogLines::mergeInto($project, $data, $sinceMs);
        }

        return response()->json($data);
    }

    public function metrics(Project $project, Deployment $deployment): JsonResponse
    {
        $quick = request()->boolean('quick', false);
        $data = app(KnativeMetricsService::class)->getDeploymentMetrics(DeployTarget::project($project, $deployment), $deployment, $quick);

        return response()->json($data);
    }

    #[OpenApiResponse(409, 'The deployment serves while a rollout is under way', type: 'array{message: string, code: "rollout_in_progress"}')]
    public function destroy(Request $request, Project $project, Deployment $deployment): JsonResponse
    {
        // A deployment of a blue-green / canary rollout: the new one's rollout is aborted first
        // (the traffic goes back), a kept previous revision is released; the one that serves
        // while a rollout is under way cannot go (409 rollout_in_progress)
        try {
            app(RolloutService::class)->beforeDelete($deployment, $request->user()?->username);
        } catch (RolloutActionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
        $deployment->refresh();

        // If the deployment is live, tear down the k8s resources first — but not the ones a
        // newer live deployment of the project shares by name (Knative Service, CronJob, consumer)
        if (in_array($deployment->status, ['active', 'pending', 'running', 'shutting_down'])) {
            $deployer = app(KnativeDeployService::class);
            // A newer live deployment of the same environment shares the names
            $newer = $deployment->siblings()
                ->whereKeyNot($deployment->id)
                ->whereIn('status', ['pending', 'running', 'active'])
                ->exists();

            $newer
                ? $deployer->teardownSuperseded($project, $deployment)
                : $deployer->teardown($project, $deployment);
        }

        $deploymentId = $deployment->id;
        $deployment->delete();

        $this->broadcastModel('deployment.deleted', [
            'project_id' => $project->id,
            'deployment_id' => $deploymentId,
        ]);

        return response()->json(null, 204);
    }

    /**
     * PATCH /api/projects/{project}/deployments/{deployment}/status
     *
     * Sets a deployment's status — the console applies the status the log check suggests
     * (useDeploymentLogs.js). DeploymentStatusChanged lets the listeners act on it, e.g.
     * ProcessTeardown removes the Knative resources of a deployment set to shutting_down.
     */
    #[OpenApiResponse(409, 'A stopped project\'s deployment set live again', type: 'array{message: string, code: "project_stopped", reason: string|null, level: string|null}')]
    public function updateStatus(Request $request, Project $project, Deployment $deployment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Deployment::STATUSES)],
        ]);

        // A stopped project's deployment cannot be set live again by hand
        if (in_array($data['status'], Deployment::LIVE, true) && $project->isStopped()) {
            ProjectAdmission::assertDeployable($project);
        }

        $previous = $deployment->status;
        $deployment->update(['status' => $data['status']]);

        event(new DeploymentStatusChanged($deployment, $previous, $data['status']));

        return response()->json(new DeploymentResource($deployment->load(['version', ...DeploymentResource::rolloutRelations()])));
    }
}
