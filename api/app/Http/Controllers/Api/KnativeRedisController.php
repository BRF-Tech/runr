<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\ConfigureRedisRequest;
use App\Http\Requests\KnativeRegion\TestRedisRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\InstallRedisJob;
use App\Jobs\RemoveRedisJob;
use App\Models\KnativeRegion;
use App\Services\KnativeRedisService;
use Illuminate\Http\JsonResponse;

/**
 * @tags Knative Redis
 */
class KnativeRedisController extends Controller
{
    /**
     * Test Redis connectivity from inside the region's cluster
     */
    public function testRedis(TestRedisRequest $request, KnativeRegion $knativeRegion, KnativeRedisService $redisService): JsonResponse
    {
        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        $validated = $request->validated();

        $result = $redisService->testConnection(
            $knativeRegion,
            $validated['address'],
            $validated['password'] ?? null,
        );

        return response()->json($result);
    }

    /**
     * Set up Redis for the region's queue triggers (queued)
     *
     * Configures Redis for queue trigger support in a background job. In managed mode RunR runs
     * Redis in the cluster; the other modes use the Redis at the given address.
     */
    public function configureRedis(ConfigureRedisRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        $validated = $request->validated();

        $knativeRegion->update([
            'redis_mode' => $validated['mode'],
            'knative_redis_status' => 'installing',
            'knative_redis_status_message' => '',
        ]);

        InstallRedisJob::dispatch(
            $knativeRegion,
            $validated['mode'],
            $validated['address'] ?? null,
            $validated['password'] ?? null,
            auth()->id(),
        );

        return response()->json([
            'message' => __('api.redis.installation_queued'),
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ], 202);
    }

    /**
     * Remove the region's Redis configuration (queued)
     *
     * Runs as a background job. A Redis that RunR runs in the cluster (managed mode) is deleted
     * too.
     */
    public function removeRedis(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        $knativeRegion->update([
            'knative_redis_status' => 'uninstalling',
            'knative_redis_status_message' => '',
        ]);

        RemoveRedisJob::dispatch($knativeRegion, auth()->id());

        return response()->json([
            'message' => __('api.redis.removal_queued'),
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    /**
     * Get the region's Redis mode and status
     */
    public function redisStatus(KnativeRegion $knativeRegion): JsonResponse
    {
        return response()->json([
            'redis_mode' => $knativeRegion->redis_mode,
            'knative_redis_status' => $knativeRegion->knative_redis_status,
            'redis_address' => $knativeRegion->redis_address,
            'knative_redis_status_message' => $knativeRegion->knative_redis_status_message,
            'has_redis' => $knativeRegion->has_redis,
        ]);
    }
}
