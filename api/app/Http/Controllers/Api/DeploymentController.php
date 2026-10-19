<?php

namespace App\Http\Controllers\Api;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Http\Requests\Project\DeployProjectRequest;
use App\Http\Resources\DeploymentResource;
use App\Mcp\Hosting\CallLogLines;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\KnativeDeployService;
use App\Services\KnativeLogService;
use App\Services\KnativeMetricsService;
use App\Services\Projects\LiveDeployments;
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

    public function index(Project $project): AnonymousResourceCollection
    {
        $deployments = $project->deployments()->with('version')->latest()->get();

        return DeploymentResource::collection($deployments);
    }

    // The deploy checks raise their refusals as exceptions (ProjectAdmission), which the OpenAPI
    // generator does not see: 409 project_stopped, 422 a deploy guard's code (fixed_allocation_*,
    // fixed_max_scale_required, docs/billing.md#errors) or a version / region / policy message
    #[OpenApiResponse(409, 'The project is stopped', type: 'array{message: string, code: "project_stopped", reason: string|null, level: string|null}')]
    #[OpenApiResponse(422, 'A deploy check or the validation refused the deploy', type: 'array{message: string, code?: string, errors?: array<string, string[]>}')]
    public function deploy(DeployProjectRequest $request, Project $project): JsonResponse
    {
        if ($project->isRuntimeEol()) {
            abort(422, __('api.project.eol_deploy'));
        }

        $data = $request->validated();

        // Stopped projects (409 project_stopped) and module guards, e.g. billing's fixed
        // allocations — with the deploy's overrides (deploy_config)
        ProjectAdmission::assertDeployable($project, DeployIntent::production($data['deploy_config'] ?? null));

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

        // Data residency + supply-chain policy gate (docs/supply-chain.md); throws 422 when blocked
        $policy = app(DeployPolicyGate::class)->authorize($request, $project, $version);

        // Every live deployment (a pending one too) goes to shutting_down: only the new one goes live
        app(LiveDeployments::class)->supersede($project);

        $deployment = $project->deployments()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => $request->user()->username,
            'deploy_config' => $data['deploy_config'] ?? null,
        ]);

        app(DeployPolicyGate::class)->recordFor($policy, $deployment);

        event(new DeploymentCreated($deployment, $project, $version, auth()->id()));

        $resource = new DeploymentResource($deployment->load('version'));
        $this->broadcastModel('deployment.created', [
            'project_id' => $project->id,
            'deployment' => EnvVisibility::withoutEnv(fn () => $resource->resolve()),
        ]);

        return response()->json($resource, 202);
    }

    public function show(Project $project, Deployment $deployment): DeploymentResource
    {
        return new DeploymentResource($deployment->load('version'));
    }

    public function logs(Project $project, Deployment $deployment): JsonResponse
    {
        $deployment->load('version');
        $sinceMs = request()->integer('since') ?: null;
        $data = app(KnativeLogService::class)->getDeploymentLogs($project, $deployment, $sinceMs);
        // Calls through the MCP gateway of a hosted MCP server (pod "mcp-gateway", docs/mcp.md)
        if (in_array($deployment->status, ['pending', 'running', 'active'], true)) {
            $data = CallLogLines::mergeInto($project, $data, $sinceMs);
        }

        return response()->json($data);
    }

    public function metrics(Project $project, Deployment $deployment): JsonResponse
    {
        $quick = request()->boolean('quick', false);
        $data = app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment, $quick);

        return response()->json($data);
    }

    public function destroy(Project $project, Deployment $deployment): JsonResponse
    {
        // If the deployment is live, tear down the k8s resources first — but not the ones a
        // newer live deployment of the project shares by name (Knative Service, CronJob, consumer)
        if (in_array($deployment->status, ['active', 'pending', 'running', 'shutting_down'])) {
            $deployer = app(KnativeDeployService::class);
            $newer = $project->deployments()
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

        return response()->json(new DeploymentResource($deployment->load('version')));
    }
}
