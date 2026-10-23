<?php

namespace App\Http\Controllers\Api\ApiProduct;

use App\Http\Controllers\Api\Controller;
use App\Models\KnativeRegion;
use App\Services\ApiProduct\ApiUsageIngest;
use App\Services\ApiProduct\GatewayAuthConfig;
use App\Services\ApiProduct\GatewayAuthToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Callbacks of a region's gateway-auth service (runtime/gateway-auth) — outside the user
 * authentication, with the region's token (GatewayAuthToken):
 *
 *   GET  /api/internal/gateway-auth/{region}/config  what it enforces; If-None-Match → 304
 *   POST /api/internal/gateway-auth/{region}/usage   {batch, entries: [...]} the calls it counted
 *
 * @tags API product
 *
 * @unauthenticated
 */
class GatewayAuthInternalController extends Controller
{
    public function __construct(private readonly GatewayAuthToken $tokens) {}

    public function config(Request $request, string $region, GatewayAuthConfig $config): JsonResponse|Response
    {
        $model = $this->region($request, $region);
        if (! $model) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $etag = $config->etag($model);
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->noContent(304)->header('ETag', $etag);
        }

        $payload = $config->payload($model, $etag);

        return response()->json($payload)
            ->header('ETag', '"'.$payload['version'].'"')
            ->header('Cache-Control', 'no-store');
    }

    public function usage(Request $request, string $region, ApiUsageIngest $ingest): JsonResponse
    {
        $model = $this->region($request, $region);
        if (! $model) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $batch = $request->json('batch');
        $entries = $request->json('entries');
        if (! is_string($batch) || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $batch)
            || ! is_array($entries) || count($entries) > ApiUsageIngest::MAX_ENTRIES) {
            return response()->json(['message' => 'invalid usage report'], 422);
        }

        return response()->json(['recorded' => $ingest->ingest($model, $batch, array_values($entries))], 202);
    }

    private function region(Request $request, string $regionId): ?KnativeRegion
    {
        if (! preg_match('/^[0-9A-Za-z]{26}$/', $regionId) || ! $this->tokens->verify($regionId, $request->bearerToken())) {
            return null;
        }

        return KnativeRegion::query()->find($regionId);
    }
}
