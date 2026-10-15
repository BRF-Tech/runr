<?php

/*
|--------------------------------------------------------------------------
| Observability v2 routes (docs/observability.md) — loaded by ObservabilityServiceProvider
|--------------------------------------------------------------------------
| authenticated: ['api', 'auth:sanctum'], prefix api — permissions in config/route_permissions.php
| public:        ['api'], prefix api — public status pages
| internal:      prefix api/internal (no user session) — callbacks from the regions with their own
|                credentials (replay ingest token, region agent token)
*/

use App\Http\Controllers\Api\Observability\AlertChannelController;
use App\Http\Controllers\Api\Observability\AlertRuleController;
use App\Http\Controllers\Api\Observability\ObservabilityIngestController;
use App\Http\Controllers\Api\Observability\ProjectObservabilityController;
use App\Http\Controllers\Api\Observability\PublicStatusPageController;
use App\Http\Controllers\Api\Observability\RegionTracingController;
use App\Http\Controllers\Api\Observability\ReplayCaptureController;
use App\Http\Controllers\Api\Observability\StatusPageController;
use App\Http\Controllers\Api\Observability\TraceController;
use App\Http\Controllers\Api\Observability\UptimeCheckController;
use App\Http\Middleware\NoStoreResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function () {
    // Region tracing backend (changes carry region.mutable like every region-changing route)
    Route::get('/observability/regions/{knativeRegion}/tracing', [RegionTracingController::class, 'show']);
    Route::put('/observability/regions/{knativeRegion}/tracing', [RegionTracingController::class, 'update'])->middleware('region.mutable');
    Route::post('/observability/regions/{knativeRegion}/tracing/install', [RegionTracingController::class, 'install'])->middleware('region.mutable');
    Route::delete('/observability/regions/{knativeRegion}/tracing', [RegionTracingController::class, 'destroy'])->middleware('region.mutable');

    // Project switches
    Route::get('/projects/{project}/observability', [ProjectObservabilityController::class, 'show']);
    Route::put('/projects/{project}/observability/tracing', [ProjectObservabilityController::class, 'updateTracing']);
    Route::put('/projects/{project}/observability/replay', [ProjectObservabilityController::class, 'updateReplay']);

    // Traces — read live from Tempo (spans keep arriving): no-store, the response cache never keeps them
    Route::middleware(NoStoreResponse::class)->group(function () {
        Route::get('/projects/{project}/traces', [TraceController::class, 'index']);
        Route::get('/projects/{project}/traces/{traceId}', [TraceController::class, 'show']);
        Route::get('/projects/{project}/traces/{traceId}/logs', [TraceController::class, 'logs']);
    });

    // Request replay
    Route::get('/projects/{project}/replays', [ReplayCaptureController::class, 'index']);
    Route::delete('/projects/{project}/replays', [ReplayCaptureController::class, 'destroyAll']);
    Route::get('/projects/{project}/replays/{capture}', [ReplayCaptureController::class, 'show']);
    Route::post('/projects/{project}/replays/{capture}/replay', [ReplayCaptureController::class, 'replay']);
    Route::delete('/projects/{project}/replays/{capture}', [ReplayCaptureController::class, 'destroy']);

    // Uptime checks + incidents
    Route::get('/projects/{project}/uptime-checks', [UptimeCheckController::class, 'index']);
    Route::post('/projects/{project}/uptime-checks', [UptimeCheckController::class, 'store']);
    Route::put('/projects/{project}/uptime-checks/{check}', [UptimeCheckController::class, 'update']);
    Route::delete('/projects/{project}/uptime-checks/{check}', [UptimeCheckController::class, 'destroy']);
    Route::get('/projects/{project}/uptime-checks/{check}/results', [UptimeCheckController::class, 'results']);
    Route::post('/projects/{project}/uptime-checks/{check}/run', [UptimeCheckController::class, 'run']);
    Route::get('/projects/{project}/uptime-incidents', [UptimeCheckController::class, 'incidents']);
    Route::patch('/projects/{project}/uptime-incidents/{incident}', [UptimeCheckController::class, 'updateIncident']);

    // Status page of the organization
    Route::get('/observability/status-page', [StatusPageController::class, 'show']);
    Route::put('/observability/status-page', [StatusPageController::class, 'update']);

    // Alert channels, rules, fired alerts, delivery log
    Route::get('/observability/alert-channels', [AlertChannelController::class, 'index']);
    Route::post('/observability/alert-channels', [AlertChannelController::class, 'store']);
    Route::put('/observability/alert-channels/{channel}', [AlertChannelController::class, 'update']);
    Route::delete('/observability/alert-channels/{channel}', [AlertChannelController::class, 'destroy']);
    Route::post('/observability/alert-channels/{channel}/test', [AlertChannelController::class, 'test']);
    Route::get('/observability/alert-rules', [AlertRuleController::class, 'index']);
    Route::post('/observability/alert-rules', [AlertRuleController::class, 'store']);
    Route::put('/observability/alert-rules/{rule}', [AlertRuleController::class, 'update']);
    Route::delete('/observability/alert-rules/{rule}', [AlertRuleController::class, 'destroy']);
    Route::get('/observability/alert-events', [AlertRuleController::class, 'events']);
    Route::get('/observability/alert-deliveries', [AlertRuleController::class, 'deliveries']);
    Route::post('/observability/alert-deliveries/{delivery}/retry', [AlertRuleController::class, 'retry']);
});

// Public status pages — never behind auth:sanctum
Route::middleware(['api'])->prefix('api')->group(function () {
    Route::get('/public/status-pages', [PublicStatusPageController::class, 'byDomain'])->middleware('throttle:60,1');
    Route::get('/public/status-pages/{slug}', [PublicStatusPageController::class, 'show'])->middleware('throttle:60,1');
});

// Callbacks from the regions (own credentials, no session / tenant middleware)
Route::prefix('api/internal/observability')->middleware('throttle:observability-ingest')->group(function () {
    Route::post('/replay/{key}', [ObservabilityIngestController::class, 'replay']);
    Route::get('/agent/{region}/uptime-checks', [ObservabilityIngestController::class, 'agentChecks']);
    Route::post('/agent/{region}/uptime-results', [ObservabilityIngestController::class, 'agentResults']);
});
