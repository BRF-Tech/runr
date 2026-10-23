<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\Ai\AiModelBindingResource;
use App\Models\AiModelBinding;
use App\Models\AiModelService;
use App\Models\Project;
use App\Services\CacheVersionService;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\Residency\RegionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Models a project uses (docs/huggingface.md#binding-models-to-functions): every binding gives
 * the project's containers RUNR_MODEL_<ALIAS>_* env vars (and, with mount_weights, the model's
 * offline snapshot read-only) on its next deploy. Any language reaches the model over HTTP.
 *
 * @tags AI · Model services
 */
class ProjectModelBindingController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $bindings = $project->modelBindings()->with('service.model', 'service.region')->get();

        return response()->json([
            'data' => AiModelBindingResource::collection($bindings)->resolve(),
            'available' => AiModelService::where('knative_region_id', $project->knative_region_id)
                ->orderBy('name')
                ->get(['id', 'name', 'engine', 'status'])
                ->toArray(),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        // A model service runs in one region: a global project's other copies could not reach it
        if ($project->isGlobal()) {
            return response()->json(['message' => __('api.global.model_binding_refused'), 'errors' => ['ai_model_service_id' => [__('api.global.model_binding_refused')]]], 422);
        }

        $data = $request->validate([
            'ai_model_service_id' => [
                'required', 'string', 'exists:ai_model_services,id',
                Rule::unique('ai_model_bindings', 'ai_model_service_id')->where('project_id', $project->id),
            ],
            'alias' => [
                'nullable', 'string', 'regex:'.AiModelBinding::ALIAS_PATTERN,
                Rule::unique('ai_model_bindings', 'alias')->where('project_id', $project->id),
            ],
            'mount_weights' => 'nullable|boolean',
        ]);

        $service = AiModelService::with('model')->findOrFail($data['ai_model_service_id']);

        // Data residency (docs/supply-chain.md): the bound service's region must be allowed
        app(RegionPolicy::class)->authorize($service->knative_region_id, RegionPolicy::BINDING_CREATE, field: 'ai_model_service_id');
        if ($response = $this->refuse($project, $service, (bool) ($data['mount_weights'] ?? false))) {
            return $response;
        }

        $alias = $data['alias'] ?? AiModelBinding::aliasFor($service->name);
        if (AiModelBinding::where('project_id', $project->id)->where('alias', $alias)->exists()) {
            return response()->json(['message' => __('ai.bindings.alias_taken', ['alias' => $alias]), 'errors' => ['alias' => [__('ai.bindings.alias_taken', ['alias' => $alias])]]], 422);
        }

        $binding = AiModelBinding::create([
            'project_id' => $project->id,
            'ai_model_service_id' => $service->id,
            'alias' => $alias,
            'mount_weights' => (bool) ($data['mount_weights'] ?? false),
        ]);
        $this->bumpCache();
        // the bound project may call the service on the internal network now
        app(InternalNetworkService::class)->syncModelService($service);

        return response()->json(new AiModelBindingResource($binding->load('service.model', 'service.region')), 201);
    }

    public function update(Request $request, Project $project, AiModelBinding $modelBinding): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $data = $request->validate([
            'alias' => [
                'sometimes', 'string', 'regex:'.AiModelBinding::ALIAS_PATTERN,
                Rule::unique('ai_model_bindings', 'alias')->where('project_id', $project->id)->ignore($modelBinding->id),
            ],
            'mount_weights' => 'sometimes|boolean',
        ]);

        $modelBinding->load('service.model');
        if (($data['mount_weights'] ?? false) && ($response = $this->refuse($project, $modelBinding->service, true))) {
            return $response;
        }

        $modelBinding->update($data);
        $this->bumpCache();

        return response()->json(new AiModelBindingResource($modelBinding->fresh(['service.model', 'service.region'])));
    }

    public function destroy(Project $project, AiModelBinding $modelBinding): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $service = $modelBinding->service;
        $modelBinding->delete();
        $this->bumpCache();
        app(InternalNetworkService::class)->syncModelService($service);

        return response()->json(null, 204);
    }

    /** A binding works inside the service's region only; weights need an offline snapshot there. */
    private function refuse(Project $project, AiModelService $service, bool $mountWeights): ?JsonResponse
    {
        if ($service->knative_region_id !== $project->knative_region_id) {
            return response()->json([
                'message' => __('ai.bindings.other_region'),
                'errors' => ['ai_model_service_id' => [__('ai.bindings.other_region')]],
            ], 422);
        }

        if ($mountWeights && ! $service->model?->readySnapshotIn($project->knative_region_id)) {
            return response()->json([
                'message' => __('ai.bindings.weights_need_snapshot'),
                'errors' => ['mount_weights' => [__('ai.bindings.weights_need_snapshot')]],
            ], 422);
        }

        return null;
    }

    /** Project GETs are response-cached; a binding changes what they show. */
    private function bumpCache(): void
    {
        try {
            app(CacheVersionService::class)->bumpMany(['projects']);
        } catch (\Throwable) {
            // cache unavailable: nothing cached either
        }
    }
}
