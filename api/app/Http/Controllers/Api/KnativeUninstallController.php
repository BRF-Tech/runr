<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\KnativeRegionResource;
use App\Jobs\UninstallKnativeJob;
use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeValidationTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Knative Uninstall
 */
class KnativeUninstallController extends Controller
{
    use KnativeValidationTrait;

    /**
     * Uninstall Knative Operator.
     */
    public function uninstallOperator(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($err = $this->validateRegion($knativeRegion)) {
            return $err;
        }

        $servingStatus = $knativeRegion->knative_serving_status ?? 'not_installed';
        if (! in_array($servingStatus, ['not_installed', 'error'])) {
            return response()->json(['message' => __('api.region.serving_uninstall_first')], 422);
        }

        UninstallKnativeJob::dispatch($knativeRegion, 'operator', auth()->id());

        return response()->json([
            'message' => 'Operator uninstall started.',
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    /**
     * Uninstall Knative Serving.
     * The region Traefik stays while it serves non-Knative Ingresses (e.g. the RunR console on a
     * single-cluster install); `remove_traefik: true` removes it anyway.
     */
    public function uninstallServing(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($err = $this->validateRegion($knativeRegion)) {
            return $err;
        }

        UninstallKnativeJob::dispatch($knativeRegion, 'serving', auth()->id(), $request->boolean('remove_traefik'));

        return response()->json([
            'message' => 'Serving uninstall started.',
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }
}
