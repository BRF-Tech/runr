<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\Ai\AiModelBindingResource;
use App\Http\Resources\Ai\AiModelServiceResource;
use App\Jobs\Ai\DeployModelServiceJob;
use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Rules\EnvVars;
use App\Rules\KubernetesCpu;
use App\Services\Ai\Engines\EngineCatalog;
use App\Services\Ai\Hub\HubException;
use App\Services\Ai\Models\ModelLibrary;
use App\Services\Ai\Models\ModelServiceDeployer;
use App\Services\Ai\Models\ModelServiceSpec;
use App\Services\Residency\RegionPolicy;
use App\Services\SupplyChain\DeployPolicyGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Model services (docs/huggingface.md): one-click deploy of a Hub model (or of a model of the
 * library), status, logs, playground, stop / start, delete.
 *
 * @tags AI · Model services
 */
class ModelServiceController extends Controller
{
    public function __construct(
        private ModelServiceSpec $spec,
        private ModelServiceDeployer $deployer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $services = AiModelService::with('model', 'region')->withCount('bindings')
            ->when($request->query('knative_region_id'), fn ($q, $id) => $q->where('knative_region_id', $id))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => AiModelServiceResource::collection($services)->resolve(),
            'abilities' => AiModelController::abilities(),
        ]);
    }

    public function store(Request $request, ModelLibrary $library): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $data = $request->validate(array_merge([
            'ai_model_id' => 'required_without:hub_id|nullable|string|exists:ai_models,id',
            'hub_id' => 'required_without:ai_model_id|nullable|string|max:200',
            'revision' => 'nullable|string|max:100|regex:/^[A-Za-z0-9._\/-]+$/',
            'name' => ['nullable', 'string', 'max:30', 'regex:'.AiModelService::NAME_PATTERN, Rule::unique('ai_model_services', 'name')],
            'knative_region_id' => 'required|string|exists:knative_regions,id',
            'deploy' => 'nullable|boolean',
        ], self::settingsRules()));

        $region = KnativeRegion::findOrFail($data['knative_region_id']);
        if (! ModelServiceDeployer::regionUsable($region)) {
            return response()->json(['message' => __('ai.models.region_unavailable', ['region' => $region->name])], 422);
        }

        // Data residency (docs/supply-chain.md): the organization's allowed regions only
        app(RegionPolicy::class)->authorize($region, RegionPolicy::MODEL_SERVICE);

        try {
            $model = ! empty($data['ai_model_id'])
                ? AiModel::findOrFail($data['ai_model_id'])
                : $library->findOrAddHub((string) $data['hub_id'], $data['revision'] ?? null, (string) $request->user()?->getKey());
        } catch (HubException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['hub_id' => [$e->getMessage()]]], $e->status() === 404 ? 422 : $e->status());
        }

        $deploy = (bool) ($data['deploy'] ?? true);
        $resolved = $this->spec->resolve($model, $region, $data);
        $problems = $this->spec->problems($model, $region, $resolved, null, $deploy);

        if ($problems) {
            return response()->json(['message' => reset($problems)[0], 'errors' => $problems], 422);
        }

        $service = AiModelService::create($resolved + [
            'ai_model_id' => $model->id,
            'knative_region_id' => $region->id,
            'status' => $deploy ? AiModelService::STATUS_DEPLOYING : AiModelService::STATUS_STOPPED,
            'status_message' => $deploy ? __('ai.models.deploy_queued') : null,
            'created_by' => (string) $request->user()?->getKey(),
        ]);

        if ($deploy) {
            // Supply-chain policy gate on the image (refused: the service stays, stopped)
            app(DeployPolicyGate::class)->guardModelService($request, $service->load('model', 'region'));
            DeployModelServiceJob::dispatch($service->id, (string) auth()->id());
        }

        // Its engine image onto the nodes, while the model is mirrored or before the first deploy
        app(ModelServiceDeployer::class)->prepullImages($service);

        return response()->json(new AiModelServiceResource($service->fresh(['model', 'region'])->loadCount('bindings')), 201);
    }

    public function show(AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        return response()->json(new AiModelServiceResource($service->load('model', 'region')->loadCount('bindings')));
    }

    /** Settings change; a running service is redeployed with them. */
    public function update(Request $request, AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $data = $request->validate(self::settingsRules(true));
        $service->load('model', 'region');

        $running = $service->isActive() || $service->status === AiModelService::STATUS_FAILED;
        $resolved = $this->spec->resolve($service->model, $service->region, $data, $service);
        $problems = $this->spec->problems($service->model, $service->region, $resolved, $service, $running);

        if ($problems) {
            return response()->json(['message' => reset($problems)[0], 'errors' => $problems], 422);
        }

        unset($resolved['name'], $resolved['service_name']);
        $service->update($resolved);

        if ($running) {
            app(DeployPolicyGate::class)->guardModelService($request, $service->fresh(['model', 'region']));
            $service->update(['status' => AiModelService::STATUS_DEPLOYING, 'status_message' => __('ai.models.deploy_queued')]);
            DeployModelServiceJob::dispatch($service->id, (string) auth()->id());
        }

        return response()->json(new AiModelServiceResource($service->fresh(['model', 'region'])->loadCount('bindings')));
    }

    public function deploy(AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $service->load('model', 'region');
        $problems = $this->spec->problems($service->model, $service->region, $service->only([
            'engine', 'device', 'mode', 'gpu', 'cpu', 'memory_mb', 'min_scale', 'max_scale', 'scale_to_zero', 'image', 'served_name',
        ]), $service, true);

        if ($problems) {
            return response()->json(['message' => reset($problems)[0], 'errors' => $problems], 422);
        }

        // Data residency + supply-chain policy gate (docs/supply-chain.md)
        app(DeployPolicyGate::class)->guardModelService(request(), $service);

        $service->update(['status' => AiModelService::STATUS_DEPLOYING, 'status_message' => __('ai.models.deploy_queued')]);
        DeployModelServiceJob::dispatch($service->id, (string) auth()->id());

        return response()->json(new AiModelServiceResource($service->fresh(['model', 'region'])->loadCount('bindings')), 202);
    }

    public function stop(AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $this->deployer->stop($service->load('region'));

        return response()->json(new AiModelServiceResource($service->fresh(['model', 'region'])->loadCount('bindings')), 202);
    }

    public function destroy(AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $projects = $service->bindings()->with('project:id,name')->get()->pluck('project.name')->filter();
        if ($projects->isNotEmpty()) {
            return response()->json(['message' => __('ai.models.service_bound', ['projects' => $projects->implode(', ')])], 409);
        }

        $this->deployer->teardown($service->load('region'));
        $service->delete();

        return response()->json(null, 204);
    }

    public function logs(Request $request, AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        try {
            $pods = $this->deployer->logs($service->load('region'), (int) $request->query('tail', 200));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'pods' => []], 502);
        }

        return response()->json(['pods' => $pods]);
    }

    /** Playground: one request to the service (allowed paths per engine). */
    public function invoke(Request $request, AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $data = $request->validate([
            'path' => 'required|string|max:200',
            'method' => ['nullable', Rule::in(['GET', 'POST'])],
            'body' => 'nullable',
        ]);

        $service->load('model', 'region');
        if (! ModelServiceDeployer::allowedPath($service, $data['path'])) {
            return response()->json(['message' => __('ai.models.path_not_allowed', ['path' => $data['path']])], 422);
        }

        $body = $data['body'] ?? null;
        if (is_string($body) && strlen($body) > (int) config('ai.models.invoke_max_bytes', 4 * 1024 * 1024)) {
            return response()->json(['message' => __('ai.models.body_too_large')], 413);
        }

        try {
            return response()->json($this->deployer->invoke($service, $data['method'] ?? 'POST', $data['path'], $body));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function bindings(AiModelService $service): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $bindings = $service->bindings()->with('project:id,name,knative_region_id', 'service.model', 'service.region')->get();

        return response()->json(['data' => AiModelBindingResource::collection($bindings)->resolve()]);
    }

    /** Settings of a service (create and update). */
    private static function settingsRules(bool $update = false): array
    {
        $sometimes = $update ? 'sometimes' : 'nullable';
        $max = (int) config('ai.gpu.max_count', 8);

        return [
            'mode' => [$sometimes, Rule::in(AiModelService::MODES)],
            'device' => [$sometimes, Rule::in(AiModelService::DEVICES)],
            'engine' => [$sometimes, Rule::in(array_merge(['auto'], array_keys(EngineCatalog::ENGINES)))],
            'gpu' => "{$sometimes}|nullable|array",
            'gpu.count' => "required_with:gpu|integer|min:1|max:{$max}",
            'gpu.type' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._()-]*$/'],
            'gpu.memory_min' => 'nullable|integer|min:1|max:1048576',
            'cpu' => [$sometimes, 'nullable', 'string', new KubernetesCpu],
            'memory_mb' => "{$sometimes}|nullable|integer|min:256|max:262144",
            'min_scale' => "{$sometimes}|nullable|integer|min:0|max:10",
            'max_scale' => "{$sometimes}|nullable|integer|min:1|max:100",
            'scale_to_zero' => "{$sometimes}|nullable|boolean",
            'timeout_seconds' => "{$sometimes}|nullable|integer|min:1|max:3600",
            'image' => "{$sometimes}|nullable|string|max:500|regex:/^[a-z0-9][a-z0-9._\\/:@-]*$/",
            'args' => "{$sometimes}|nullable|array|max:50",
            'args.*' => 'string|max:1000',
            'env_vars' => [$sometimes, 'nullable', 'array', new EnvVars],
            'port' => "{$sometimes}|nullable|integer|min:1|max:65535",
            'health_path' => "{$sometimes}|nullable|string|max:200|regex:/^\\/[A-Za-z0-9._~\\/-]*$/",
            'served_name' => "{$sometimes}|nullable|string|max:100|regex:/^[A-Za-z0-9._\\/-]+$/",
        ];
    }
}
