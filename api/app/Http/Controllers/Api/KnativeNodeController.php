<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\ApplyWorkersRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Models\KnativeRegion;
use App\Services\KnativeNodeService;
use Illuminate\Http\JsonResponse;

/**
 * @tags Knative Nodes
 */
class KnativeNodeController extends Controller
{
    /**
     * List worker nodes from the cluster.
     */
    public function listNodes(KnativeRegion $knativeRegion, KnativeNodeService $nodeService): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            $mockNodes = [
                ['name' => 'demo-worker-1', 'status' => 'Ready', 'cpu_m' => 2000, 'memory_mb' => 4096],
                ['name' => 'demo-worker-2', 'status' => 'Ready', 'cpu_m' => 2000, 'memory_mb' => 4096],
                ['name' => 'demo-worker-3', 'status' => 'Ready', 'cpu_m' => 4000, 'memory_mb' => 8192],
            ];

            return response()->json(['nodes' => $mockNodes]);
        }

        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        try {
            $nodes = $nodeService->listWorkerNodes($knativeRegion);
        } catch (\Throwable $e) {
            $knativeRegion->update([
                'kubeconfig' => null,
                'knative_status' => 'error',
                'knative_status_message' => __('api.region.connect_failed', ['message' => $e->getMessage()]),
                'knative_status_checked_at' => now(),
            ]);

            return response()->json([
                'message' => __('api.region.connect_failed', ['message' => $e->getMessage()]),
                'kubeconfig_cleared' => true,
                'region' => new KnativeRegionResource($knativeRegion->fresh()),
            ], 400);
        }

        return response()->json(['nodes' => $nodes]);
    }

    /**
     * Apply worker node selection.
     */
    public function applyWorkers(ApplyWorkersRequest $request, KnativeRegion $knativeRegion, KnativeNodeService $nodeService): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        $nodeNames = $request->validated()['nodes'];

        $result = $nodeService->applyWorkerSelection($knativeRegion, $nodeNames);

        return response()->json([
            'message' => __('api.region.workers_updated'),
            'total_cpu_m' => $result['total_cpu_m'],
            'total_memory_mb' => $result['total_memory_mb'],
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }
}
