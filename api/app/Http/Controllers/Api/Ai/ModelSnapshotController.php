<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\Ai\AiModelSnapshotResource;
use App\Jobs\Ai\StartModelSnapshotJob;
use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\KnativeRegion;
use App\Services\Ai\Models\ModelStorage;
use App\Services\Ai\Models\SnapshotService;
use App\Services\Residency\RegionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Offline copies of a model in regions ("closed circuit", docs/huggingface.md#offline-mode).
 *
 * @tags AI · Models
 */
class ModelSnapshotController extends Controller
{
    public function index(AiModel $model): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        return response()->json(['data' => AiModelSnapshotResource::collection($model->snapshots()->with('region')->get())->resolve()]);
    }

    /** Mirror a Hub model into a region (or re-run a failed mirror / import). */
    public function store(Request $request, AiModel $model, ModelStorage $storage): JsonResponse
    {
        abort_unless(Gate::allows(in_array($model->source, ['pvc', 'oci'], true) ? 'models.manage' : 'models.deploy'), 403);

        $data = $request->validate(['knative_region_id' => 'required|string|exists:knative_regions,id']);
        $region = KnativeRegion::findOrFail($data['knative_region_id']);

        if ($model->source === 'upload') {
            return response()->json(['message' => __('ai.models.snapshot_needs_upload')], 422);
        }
        if (in_array($model->source, ['pvc', 'oci'], true) && ! $model->snapshots()->where('knative_region_id', $region->id)->exists()) {
            // An import reads from one region's volume / registry
            return response()->json(['message' => __('ai.models.import_other_region')], 422);
        }
        if (! $region->is_demo && (! $region->kubeconfig || ! $region->is_active)) {
            return response()->json(['message' => __('ai.models.region_unavailable', ['region' => $region->name])], 422);
        }

        // Data residency (docs/supply-chain.md): model weights only into allowed regions
        app(RegionPolicy::class)->authorize($region, RegionPolicy::MODEL_SNAPSHOT);

        $revision = $model->isHub() ? $model->pinnedRevision() : 'local';
        $snapshot = AiModelSnapshot::firstOrNew([
            'ai_model_id' => $model->id,
            'knative_region_id' => $region->id,
            'revision' => $revision,
        ]);

        if ($snapshot->exists && ($snapshot->isBusy() && $snapshot->status !== AiModelSnapshot::STATUS_PENDING)) {
            return response()->json(['message' => __('ai.models.snapshot_busy')], 409);
        }
        if ($snapshot->exists && $snapshot->isReady() && ! $request->boolean('force')) {
            return response()->json(new AiModelSnapshotResource($snapshot->load('region')), 200);
        }

        $snapshot->fill([
            'source' => $model->source,
            'path' => $snapshot->path ?: $storage->snapshotSubPath($model, $revision),
            'status' => AiModelSnapshot::STATUS_PENDING,
            'message' => null,
        ])->save();

        StartModelSnapshotJob::dispatch($snapshot->id);

        return response()->json(new AiModelSnapshotResource($snapshot->fresh('region')), 202);
    }

    public function destroy(AiModel $model, AiModelSnapshot $snapshot): JsonResponse
    {
        abort_unless(Gate::allows('models.manage'), 403);

        $users = AiModelService::where('snapshot_id', $snapshot->id)
            ->where('status', '!=', AiModelService::STATUS_STOPPED)
            ->pluck('name');
        if ($users->isNotEmpty()) {
            return response()->json(['message' => __('ai.models.snapshot_in_use', ['services' => $users->implode(', ')])], 409);
        }

        $snapshot->update(['status' => AiModelSnapshot::STATUS_DELETING]);
        StartModelSnapshotJob::dispatch($snapshot->id, true);

        return response()->json(null, 204);
    }

    public function logs(AiModel $model, AiModelSnapshot $snapshot): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        try {
            $logs = app(SnapshotService::class)->logs($snapshot);
        } catch (\Throwable $e) {
            $logs = $e->getMessage();
        }

        return response()->json(['logs' => $logs]);
    }
}
