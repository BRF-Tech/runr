<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\ConfigureRegistryRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\InstallRegionRegistryJob;
use App\Models\KnativeRegion;
use App\Services\ZotRegistryService;
use Illuminate\Http\JsonResponse;

class RegistryController extends Controller
{
    /**
     * Get the region's registry status
     *
     * The registry's mode, address and status (with its message), and whether the region has one.
     */
    public function status(KnativeRegion $knativeRegion): JsonResponse
    {
        return response()->json([
            'registry_mode' => $knativeRegion->registry_mode,
            'registry_address' => $knativeRegion->registry_address,
            'registry_status' => $knativeRegion->registry_status,
            'registry_status_message' => $knativeRegion->registry_status_message,
            'has_registry' => $knativeRegion->has_registry,
        ]);
    }

    /**
     * Set up the registry of an existing region.
     *
     * Runs as a background job: for a region created without a registry, whose registry was
     * removed, or with an external registry to replace. An internal registry is removed first
     * (DELETE …/registry): its volume holds the images.
     */
    public function store(ConfigureRegistryRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.registry.cannot_modify_demo')], 403);
        }
        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }
        if ($knativeRegion->registry_status === 'installing') {
            return response()->json(['message' => __('api.region.operation_in_progress')], 409);
        }
        if ($knativeRegion->registry_mode === 'internal' && $knativeRegion->registry_status === 'ready') {
            return response()->json(['message' => __('api.registry.remove_internal_first')], 409);
        }

        $knativeRegion->update(['registry_status' => 'installing', 'registry_status_message' => 'Registry setup queued...']);
        InstallRegionRegistryJob::dispatch($knativeRegion, $request->validated(), auth()->id());

        return response()->json([
            'message' => __('api.registry.setup_started'),
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ], 202);
    }

    /**
     * Remove the region's registry
     *
     * An internal registry is uninstalled from the region's cluster; an external one is disconnected.
     */
    public function destroy(KnativeRegion $knativeRegion, ZotRegistryService $zot): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.registry.cannot_modify_demo')], 403);
        }

        $zot->uninstall($knativeRegion);

        return response()->json(new KnativeRegionResource($knativeRegion->fresh()));
    }
}
