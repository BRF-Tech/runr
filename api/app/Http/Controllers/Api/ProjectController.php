<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\GatewayService;
use App\Services\ProjectService;
use App\Services\Residency\RegionPolicy;
use App\Support\Rbac\EnvVisibility;
use App\Support\Rbac\Rbac;
use App\Support\Secrets\ProjectSecretWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    use BroadcastsModelEvents;

    public function __construct(
        private ProjectService $projectService,
        private GatewayService $gatewayService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Project::with([
            'xaasConfig', 'faasConfig', 'containerConfig',
            'aaasConfig', 'agentFlowConfig', 'maasConfig',
            'knativeRegion', 'latestVersion', 'latestDeployment.version',
            'gatewayRoute.gateway', 'creator:id,name', 'owner:id,name,username', 'secrets:id,project_id,name',
        ]);

        // Only what the user may see: every project with an organization-wide role, else project access
        $visible = app(Rbac::class)->visibleProjectIds($request->user());
        if ($visible !== null) {
            $query->whereIn('id', $visible);
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }
        if ($type = $request->query('service_type')) {
            $query->where('service_type', $type);
        }
        if ($regionId = $request->query('knative_region_id')) {
            $query->where('knative_region_id', $regionId);
        }
        if ($runtime = $request->query('runtime')) {
            $query->where('runtime', $runtime);
        }
        if ($version = $request->query('runtime_version')) {
            $query->where('runtime_version', $version);
        }

        return ProjectResource::collection($query->orderByDesc('created_at')->get());
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['created_by'] = $request->user()->id;
        // Secret variables go to project_secrets, never into the config or a version snapshot
        $secretEnv = $this->takeSecretEnv($validated);

        // Data residency (docs/supply-chain.md): the tenant may only use its allowed regions
        app(RegionPolicy::class)->authorize($validated['knative_region_id'] ?? null, RegionPolicy::PROJECT_CREATE);

        if (($validated['endpoint_type'] ?? null) === 'gateway') {
            $validated['endpoint_type'] = 'gateway';
        }

        $project = $this->projectService->create($validated);

        if ($secretEnv) {
            ProjectSecretWriter::apply($project, $secretEnv, $request->user()->id);
        }

        if ($project->endpoint_type === 'gateway' && ! empty($validated['gateway_path'])) {
            $region = $project->knativeRegion;
            $gateway = $this->gatewayService->findOrCreateForRegion($request->user(), $region);
            $this->gatewayService->addRoute($gateway, $project, $validated['gateway_path'], $validated['gateway_methods'] ?? null);
        }

        $fresh = $project->fresh([
            'containerConfig', 'xaasConfig', 'faasConfig',
            'aaasConfig', 'agentFlowConfig', 'maasConfig',
            'latestVersion', 'knativeRegion', 'gatewayRoute.gateway', 'creator:id,name', 'owner:id,name,username',
        ]);

        try {
            // Broadcast channels reach every member: no environment variables in the payload
            $this->broadcastModel('project.created', ['project' => EnvVisibility::withoutEnv(fn () => (new ProjectResource($fresh))->resolve())]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(new ProjectResource($fresh), 201);
    }

    public function show(Project $project): ProjectResource
    {
        $project->load([
            'xaasConfig', 'faasConfig', 'containerConfig',
            'aaasConfig', 'agentFlowConfig', 'maasConfig',
            'knativeRegion', 'latestVersion', 'latestDeployment.version',
            'gatewayRoute.gateway', 'creator:id,name', 'owner:id,name,username',
        ]);

        return new ProjectResource($project);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $data = $request->validated();
        $secretEnv = $this->takeSecretEnv($data);

        // Data residency (docs/supply-chain.md): moving the project to another region
        if (! empty($data['knative_region_id']) && $data['knative_region_id'] !== $project->knative_region_id) {
            app(RegionPolicy::class)->authorize($data['knative_region_id'], RegionPolicy::PROJECT_MOVE);
        }

        // Project meta
        $projectFields = array_filter([
            'description' => array_key_exists('description', $data) ? $data['description'] : null,
        ], fn ($v) => $v !== null);

        if ($projectFields) {
            $project->update($projectFields);
        }

        // knative_region_id may be null (region reset) — handled separately
        if (array_key_exists('knative_region_id', $data)) {
            $project->update(['knative_region_id' => $data['knative_region_id']]);
        }

        // Update the config
        if (! empty($data['config'])) {
            match ($project->service_type) {
                'caas' => $this->projectService->updateCaaSConfig(
                    $project->load('containerConfig'), $data['config'],
                    $request->user()->id, $data['config_message'] ?? null,
                ),
                'xaas' => $this->projectService->updateXaaSConfig(
                    $project->load('xaasConfig'), $data['config'],
                    $request->user()->id, $data['config_message'] ?? null,
                ),
                'faas' => $this->projectService->updateFaaSConfig(
                    $project->load('faasConfig'), $data['config'],
                    $request->user()->id, $data['config_message'] ?? null,
                ),
                // Former AI types: UpdateProjectRequest rejects a config change
                default => null,
            };
        }

        // Write-only secret variables (docs/security.md); they reach the workload with the next deploy
        if ($secretEnv && $project->isSupportedType()) {
            ProjectSecretWriter::apply($project, $secretEnv, $request->user()->id);
        }

        $project->load([
            'xaasConfig', 'faasConfig', 'containerConfig',
            'aaasConfig', 'agentFlowConfig', 'maasConfig',
            'knativeRegion', 'latestVersion', 'latestDeployment.version',
            'gatewayRoute.gateway', 'creator:id,name', 'owner:id,name,username',
        ]);

        try {
            $this->broadcastModel('project.updated', ['project' => EnvVisibility::withoutEnv(fn () => (new ProjectResource($project))->resolve())]);
        } catch (\Throwable $e) {
            // Payload too large for broadcast — silently skip (client will poll)
            report($e);
        }

        return new ProjectResource($project);
    }

    /**
     * Remove config.secret_env_vars from validated input.
     *
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function takeSecretEnv(array &$data): array
    {
        if (! is_array($data['config'] ?? null)) {
            return [];
        }
        $secretEnv = $data['config']['secret_env_vars'] ?? [];
        unset($data['config']['secret_env_vars']);

        return is_array($secretEnv) ? $secretEnv : [];
    }

    public function destroy(Project $project): JsonResponse
    {
        $projectId = $project->id;
        $project->delete();

        $this->broadcastModel('project.deleted', ['project_id' => $projectId]);

        return response()->json(null, 204);
    }
}
