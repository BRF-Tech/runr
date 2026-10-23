<?php

namespace App\Http\Controllers\Api\ApiProduct;

use App\Http\Controllers\Api\Controller;
use App\Services\ApiProduct\DeveloperPage;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The public developer page of a gateway (/developers/<key> in the console) — no sign-in; only
 * gateways whose owner switched the page on. Routes, base URLs and how to call them.
 *
 * @tags API product
 *
 * @unauthenticated
 */
class PublicDeveloperPageController extends Controller
{
    /** GET /api/public/developer-pages/{key} */
    #[OpenApiResponse(200, 'The gateway\'s public developer page: its base URLs and routes (health: success rate and latency of the last metrics_days days, when the owner shows it)', type: 'array{data: array{name: string, description: string|null, base_urls: list<string>, routes: list<array{path: string, methods: list<string>|null, auth: "public"|"key"|"jwt", jwt_issuer: string|null, health?: array{success_rate: float, latency_p50_ms: float|null, latency_p95_ms: float|null, latency_p99_ms: float|null}|null}>, metrics_days: int|null, key_headers: list<string>, rate_limit_headers: list<string>}}')]
    public function show(string $key, DeveloperPage $pages): JsonResponse
    {
        $payload = $pages->find($key);
        if ($payload === null) {
            return response()->json(['message' => __('api_product.developer_page.not_found')], 404);
        }

        return response()->json(['data' => $payload])->header('Cache-Control', 'public, max-age=60');
    }
}
