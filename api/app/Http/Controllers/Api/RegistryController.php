<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\KnativeRegionResource;
use App\Models\KnativeRegion;
use App\Services\ZotRegistryService;
use Illuminate\Http\JsonResponse;

class RegistryController extends Controller
{
    /**
     * Get registry status for a region.
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
     * Remove registry from a region.
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
