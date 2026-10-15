<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KnativeRegion\InstallWasmRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\InstallWasmJob;
use App\Jobs\UninstallWasmJob;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\Wasm\WasmInstallService;
use App\Services\Wasm\WasmNodes;
use App\Services\Wasm\WasmShim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * WebAssembly support of a region: runwasi shims on selected nodes (docs/wasm.md). Route rules:
 * GET regions.view, POST / DELETE sandbox.manage (plus region.mutable).
 *
 * @tags Sandbox (Runtime Isolation)
 */
class WasmController extends Controller
{
    /**
     * WebAssembly status of the region, the shims RunR installs and — unless ?cluster=0 — the
     * nodes with their eligibility, the RuntimeClasses and the Knative features still to enable.
     */
    public function show(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $cluster = null;
        $error = null;

        if ($request->query('cluster', '1') !== '0' && $knativeRegion->kubeconfig && ! $knativeRegion->is_demo) {
            try {
                $cluster = app(WasmInstallService::class)->clusterState($knativeRegion, new KubernetesClient($knativeRegion->kubeconfig));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return response()->json([
            'status' => $knativeRegion->sandbox_wasm_status ?? 'not_installed',
            'message' => $knativeRegion->sandbox_wasm_status_message,
            'shims' => array_values((array) ($knativeRegion->sandbox_wasm_shims ?? [])),
            'nodes' => $knativeRegion->sandbox_wasm_nodes,
            'available_shims' => array_map(fn (WasmShim $s) => $s->toArray(), WasmShim::all()),
            'spin_runtime' => WasmShim::SPIN_RUNTIME,
            'warning' => WasmInstallService::INSTALL_WARNING,
            // POST / DELETE require sandbox.manage (config/route_permissions.php)
            'can_manage' => Gate::allows('sandbox.manage'),
            'cluster' => $cluster,
            'cluster_error' => $error,
        ]);
    }

    /**
     * Install WebAssembly support (or change its shims / nodes): queued, progress on the region
     * (sandbox_wasm_status_message, install.progress events with component "wasm").
     */
    public function install(InstallWasmRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($blocked = $this->blocked($knativeRegion)) {
            return $blocked;
        }
        if (! $knativeRegion->has_serving) {
            return response()->json(['message' => __('wasm::messages.serving_required')], 422);
        }

        $shims = $request->validated('shims');
        $nodes = $request->validated('nodes');

        // The cluster's nodes now: only eligible ones can be selected (the job checks again)
        try {
            $items = (new KubernetesClient($knativeRegion->kubeconfig))->listClusterResources('v1', 'Node');
        } catch (\Throwable $e) {
            return response()->json(['message' => __('wasm::messages.cluster_unreachable', ['error' => $e->getMessage()])], 422);
        }
        if ($items === null) {
            return response()->json(['message' => __('wasm::messages.cluster_unreachable', ['error' => 'cannot list nodes'])], 422);
        }
        $described = collect(WasmNodes::describe($items['items'] ?? []))->keyBy('name');
        $eligible = $described->filter(fn ($n) => $n['eligible'])->keys()->all();

        if ($eligible === []) {
            $message = __('wasm::messages.no_eligible_nodes');

            return response()->json(['message' => $message, 'errors' => ['nodes' => [$message]]], 422);
        }
        if ($nodes !== null) {
            $errors = [];
            foreach ($nodes as $node) {
                if (! $described->has($node)) {
                    $errors[] = __('wasm::messages.node_unknown', ['node' => $node]);
                } elseif (! $described[$node]['eligible']) {
                    $errors[] = __('wasm::messages.node_not_eligible', ['node' => $node, 'reason' => __('wasm::messages.reasons.'.$described[$node]['reason'])]);
                }
            }
            if ($errors) {
                return response()->json(['message' => implode(' ', $errors), 'errors' => ['nodes' => $errors]], 422);
            }
        }

        // A failed change of a working install keeps it (WasmInstallService::install())
        $previousStatus = $knativeRegion->sandbox_wasm_status;
        $knativeRegion->update([
            'sandbox_wasm_status' => 'installing',
            'sandbox_wasm_status_message' => 'Installation queued...',
        ]);

        InstallWasmJob::dispatch($knativeRegion, $shims, $nodes, auth()->id(), $previousStatus);

        return response()->json([
            'message' => __('wasm::messages.install_queued'),
            'warning' => WasmInstallService::INSTALL_WARNING,
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ], 202);
    }

    /**
     * Remove WebAssembly support from the region's nodes (refused while Wasm pods run).
     */
    public function uninstall(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($blocked = $this->blocked($knativeRegion)) {
            return $blocked;
        }
        if (($knativeRegion->sandbox_wasm_status ?? 'not_installed') === 'not_installed') {
            return response()->json(['message' => __('wasm::messages.not_installed')], 422);
        }

        $knativeRegion->update([
            'sandbox_wasm_status' => 'uninstalling',
            'sandbox_wasm_status_message' => 'Removal queued...',
        ]);

        UninstallWasmJob::dispatch($knativeRegion, auth()->id());

        return response()->json([
            'message' => __('wasm::messages.uninstall_queued'),
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ], 202);
    }

    private function blocked(KnativeRegion $region): ?JsonResponse
    {
        if ($region->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }
        if (! $region->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }
        if (in_array($region->sandbox_wasm_status, ['installing', 'uninstalling'], true)) {
            return response()->json(['message' => __('api.region.operation_in_progress')], 422);
        }

        return null;
    }
}
