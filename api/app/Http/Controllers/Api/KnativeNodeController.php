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
     * List the worker nodes of the region's cluster
     *
     * Every node outside the control plane, with its capacity, readiness and whether it is a
     * selected worker. A cluster that does not answer gives a 400; the region stays as it is.
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

        // A failed read changes nothing on the region: the kubeconfig stays (the cluster may be
        // back a minute later); the periodic status check records an unreachable cluster
        try {
            $nodes = $nodeService->listWorkerNodes($knativeRegion);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => __('api.region.connect_failed', ['message' => $e->getMessage()]),
                'region' => new KnativeRegionResource($knativeRegion),
            ], 400);
        }

        return response()->json(['nodes' => $nodes]);
    }

    /**
     * Choose the worker nodes the region's projects run on
     *
     * Applies the worker node selection: the selected nodes are labelled and tainted for projects,
     * deselected ones are released, and the region's capacity is calculated again. With no node
     * selected, projects run on every schedulable node.
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
