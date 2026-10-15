<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\Ai\AiModelResource;
use App\Models\AiModel;
use App\Models\AiModelService;
use App\Services\Ai\Hub\HubException;
use App\Services\Ai\Models\ModelLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Models of the organization (docs/huggingface.md): Hub models pinned to a revision, uploaded
 * models (air-gapped) and imports (a folder of the model volume, an OCI artifact).
 *
 * @tags AI · Models
 */
class AiModelController extends Controller
{
    public function __construct(private ModelLibrary $library) {}

    public function index(): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $models = AiModel::with('snapshots.region')->withCount('services')->orderBy('name')->get();

        return response()->json([
            'data' => AiModelResource::collection($models)->resolve(),
            'abilities' => self::abilities(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $source = (string) $request->input('source', 'hub');
        abort_unless(Gate::allows(in_array($source, ['pvc', 'oci'], true) ? 'models.manage' : 'models.deploy'), 403);

        $data = $request->validate([
            'source' => ['required', Rule::in(AiModel::SOURCES)],
            'hub_id' => 'required_if:source,hub|nullable|string|max:200',
            'revision' => 'nullable|string|max:100|regex:/^[A-Za-z0-9._\/-]+$/',
            'name' => ['nullable', 'string', 'max:40', 'regex:'.AiModel::NAME_PATTERN, Rule::unique('ai_models', 'name')],
            'task' => 'required_unless:source,hub|nullable|string|max:64|regex:/^[a-z0-9-]+$/',
            'library' => 'nullable|string|max:64|regex:/^[a-z0-9_.-]+$/',
            'path' => 'required_if:source,pvc|nullable|string|max:400',
            'knative_region_id' => 'required_if:source,pvc,oci|nullable|string|exists:knative_regions,id',
            'reference' => ['required_if:source,oci', 'nullable', 'string', 'max:400', 'regex:#^[a-z0-9.-]+(:[0-9]+)?/[a-z0-9._/-]+(:[A-Za-z0-9._-]+|@sha256:[a-f0-9]{64})$#'],
        ]);

        try {
            $model = $this->library->add($data, (string) $request->user()?->getKey());
        } catch (HubException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['hub_id' => [$e->getMessage()]]], $e->status() === 404 ? 422 : $e->status());
        }

        return response()->json(new AiModelResource($model->load('snapshots.region')->loadCount('services')), 201);
    }

    public function show(AiModel $model): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        return response()->json(new AiModelResource($model->load('snapshots.region')->loadCount('services')));
    }

    public function update(Request $request, AiModel $model): JsonResponse
    {
        abort_unless(Gate::allows('models.deploy'), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:40', 'regex:'.AiModel::NAME_PATTERN, Rule::unique('ai_models', 'name')->ignore($model->id)],
            'task' => 'sometimes|nullable|string|max:64|regex:/^[a-z0-9-]+$/',
            'library' => 'sometimes|nullable|string|max:64|regex:/^[a-z0-9_.-]+$/',
        ]);

        if (isset($data['name']) && $data['name'] !== $model->name && $model->snapshots()->exists()) {
            // The name is the model's folder in every region volume
            return response()->json(['message' => __('ai.models.rename_with_snapshots'), 'errors' => ['name' => [__('ai.models.rename_with_snapshots')]]], 422);
        }

        $model->update($data);

        return response()->json(new AiModelResource($model->fresh()->load('snapshots.region')->loadCount('services')));
    }

    public function destroy(AiModel $model): JsonResponse
    {
        abort_unless(Gate::allows('models.manage'), 403);

        $services = AiModelService::where('ai_model_id', $model->id)->pluck('name');
        if ($services->isNotEmpty()) {
            return response()->json(['message' => __('ai.models.in_use', ['services' => $services->implode(', ')])], 409);
        }

        $this->library->delete($model);

        return response()->json(null, 204);
    }

    /** What the current user may do (the UI hides the rest). */
    public static function abilities(): array
    {
        return [
            'view' => Gate::allows('models.view'),
            'deploy' => Gate::allows('models.deploy'),
            'manage' => Gate::allows('models.manage'),
            'hub' => (bool) config('ai.huggingface.browse_enabled', true),
        ];
    }
}
