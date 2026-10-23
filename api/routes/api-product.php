<?php

/*
|--------------------------------------------------------------------------
| API product of the gateways (docs/api-product.md) — loaded by ApiProductServiceProvider
|--------------------------------------------------------------------------
| authenticated: ['api', 'auth:sanctum'], prefix api — authorized in the controllers (policies:
|                GatewayPolicy, ApiProductPolicy; config/route_permissions.php "@policy")
| public:        ['api'], prefix api — public developer pages
| internal:      prefix api/internal (no user session) — the regions' gateway-auth services with
|                their region token (GatewayAuthToken)
*/

use App\Http\Controllers\Api\ApiProduct\ApiConsumerController;
use App\Http\Controllers\Api\ApiProduct\ApiKeyController;
use App\Http\Controllers\Api\ApiProduct\ApiPlanController;
use App\Http\Controllers\Api\ApiProduct\ApiUsageController;
use App\Http\Controllers\Api\ApiProduct\GatewayAuthInternalController;
use App\Http\Controllers\Api\ApiProduct\GatewayDomainController;
use App\Http\Controllers\Api\ApiProduct\PublicDeveloperPageController;
use App\Http\Middleware\NoStoreResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function () {
    // Consumers: of a gateway, or of the organization (no gateway_id)
    Route::get('/api-consumers', [ApiConsumerController::class, 'index']);
    Route::post('/api-consumers', [ApiConsumerController::class, 'store']);
    Route::get('/api-consumers/{apiConsumer}', [ApiConsumerController::class, 'show']);
    Route::patch('/api-consumers/{apiConsumer}', [ApiConsumerController::class, 'update']);
    Route::delete('/api-consumers/{apiConsumer}', [ApiConsumerController::class, 'destroy']);

    // API keys: the key itself only in the answer of create / rotate
    Route::get('/api-consumers/{apiConsumer}/keys', [ApiKeyController::class, 'index']);
    Route::post('/api-consumers/{apiConsumer}/keys', [ApiKeyController::class, 'store']);
    Route::patch('/api-keys/{apiKey}', [ApiKeyController::class, 'update']);
    Route::post('/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate']);
    Route::post('/api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke']);

    // Plans: rate limits and quotas
    Route::get('/api-plans', [ApiPlanController::class, 'index']);
    Route::post('/api-plans', [ApiPlanController::class, 'store']);
    Route::patch('/api-plans/{apiPlan}', [ApiPlanController::class, 'update']);
    Route::delete('/api-plans/{apiPlan}', [ApiPlanController::class, 'destroy']);

    // Usage — the current hour keeps growing: never from the response cache
    Route::get('/api-usage', [ApiUsageController::class, 'index'])->middleware(NoStoreResponse::class);

    // The gateway's own domain (TXT verified)
    Route::put('/gateways/{gateway}/custom-domain', [GatewayDomainController::class, 'store']);
    Route::post('/gateways/{gateway}/custom-domain/verify', [GatewayDomainController::class, 'verify']);
    Route::delete('/gateways/{gateway}/custom-domain', [GatewayDomainController::class, 'destroy']);
});

// Public developer pages — never behind auth:sanctum
Route::middleware(['api'])->prefix('api')->group(function () {
    Route::get('/public/developer-pages/{key}', [PublicDeveloperPageController::class, 'show'])->middleware('throttle:60,1');
});

// The regions' gateway-auth services (own credentials, no session / tenant middleware)
Route::prefix('api/internal/gateway-auth')->middleware('throttle:gateway-auth')->group(function () {
    Route::get('/{region}/config', [GatewayAuthInternalController::class, 'config']);
    Route::post('/{region}/usage', [GatewayAuthInternalController::class, 'usage']);
});
