<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\UpdateFunctionTlsRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Models\KnativeRegion;
use App\Services\KnativeFunctionTlsService;
use Illuminate\Http\JsonResponse;

/**
 * Region-level function-domain TLS — the separate Traefik TLSStore in
 * the region's own knative-serving namespace, distinct from the console's own Ingress
 * TLS. Entirely opt-in: nothing here runs except when this endpoint is called.
 *
 * @tags Knative Regions
 */
class KnativeFunctionTlsController extends Controller
{
    public function update(UpdateFunctionTlsRequest $request, KnativeRegion $knativeRegion, KnativeFunctionTlsService $service): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        $validated = $request->validated();
        $replace = (bool) ($validated['replace_existing'] ?? false);

        $region = $validated['mode'] === 'console'
            ? $service->applyConsole($knativeRegion, $replace)
            : $service->applyCustom($knativeRegion, $validated['certificate'], $validated['privateKey'], $replace);

        return response()->json([
            'message' => __('api.region.function_tls_updated'),
            'region' => new KnativeRegionResource($region),
        ]);
    }

    /**
     * Function-domain TLS off: the region Traefik serves HTTPS with its own default certificate.
     */
    public function destroy(KnativeRegion $knativeRegion, KnativeFunctionTlsService $service): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        return response()->json([
            'message' => __('api.region.function_tls_removed'),
            'region' => new KnativeRegionResource($service->remove($knativeRegion)),
        ]);
    }
}
