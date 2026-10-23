<?php

/*
|--------------------------------------------------------------------------
| Triggers (docs/triggers.md) — loaded by App\Providers\TriggerServiceProvider
|--------------------------------------------------------------------------
| authenticated: ['api', 'auth:sanctum'], prefix api — permissions in config/route_permissions.php
| webhook inbox: prefix api/hooks (no session; the path key and the sender's signature)
| internal:      prefix api/internal/triggers (no session) — the projects' event gateways claim
|                and acknowledge stored events with their path token
*/

use App\Http\Controllers\Api\Triggers\ProjectTriggerController;
use App\Http\Controllers\Api\Triggers\TopicController;
use App\Http\Controllers\Api\Triggers\TriggerEventController;
use App\Http\Controllers\Api\Triggers\TriggerInboxController;
use App\Http\Controllers\Api\Triggers\WebhookInboxController;
use App\Http\Middleware\NoStoreResponse;
use App\Triggers\TriggerContexts;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum', NoStoreResponse::class])->prefix('api')->group(function () {
    Route::get('/trigger-types', [ProjectTriggerController::class, 'types']);

    // A project's triggers (the change reaches the cluster with the next deploy)
    Route::get('/projects/{project}/triggers', [ProjectTriggerController::class, 'index']);
    Route::post('/projects/{project}/triggers', [ProjectTriggerController::class, 'store']);
    Route::put('/projects/{project}/triggers', [ProjectTriggerController::class, 'replace']);
    Route::patch('/projects/{project}/triggers/{trigger}', [ProjectTriggerController::class, 'update']);
    Route::delete('/projects/{project}/triggers/{trigger}', [ProjectTriggerController::class, 'destroy']);

    // Stored events of a webhook / platform_event trigger (bodies: replay.manage)
    Route::get('/projects/{project}/triggers/{trigger}/events', [TriggerEventController::class, 'index']);
    Route::post('/projects/{project}/triggers/{trigger}/events/replay', [TriggerEventController::class, 'replayMany']);
    Route::get('/projects/{project}/triggers/{trigger}/events/{event}', [TriggerEventController::class, 'show']);
    Route::post('/projects/{project}/triggers/{trigger}/events/{event}/replay', [TriggerEventController::class, 'replay']);

    // The organization's topics
    Route::get('/topics', [TopicController::class, 'index']);
    Route::post('/topics', [TopicController::class, 'store']);
    Route::get('/topics/{topic}', [TopicController::class, 'show']);
    Route::patch('/topics/{topic}', [TopicController::class, 'update']);
    Route::delete('/topics/{topic}', [TopicController::class, 'destroy']);
    Route::post('/topics/{topic}/publish', [TopicController::class, 'publish'])->middleware('throttle:600,1');
});

// Webhook inbox: a stable public URL per trigger, POST and PUT (one route each, so the API
// document has an operation for both)
Route::prefix('api/hooks')->middleware('throttle:webhook-inbox')->group(function () {
    $where = ['context' => TriggerContexts::PATTERN, 'key' => '[a-z0-9]{40}'];
    Route::post('/{context}/{key}', [WebhookInboxController::class, 'receive'])->where($where);
    Route::put('/{context}/{key}', [WebhookInboxController::class, 'receivePut'])->where($where);
});

// Event gateway inbox (claim / ack), path token InboxToken
Route::prefix('api/internal/triggers')->middleware('throttle:trigger-inbox')->group(function () {
    $where = ['context' => TriggerContexts::PATTERN, 'projectId' => '[0-9A-Za-z]{26}', 'token' => '[0-9a-f]{40}'];
    Route::post('/{context}/{projectId}/{token}/claim', [TriggerInboxController::class, 'claim'])->where($where);
    Route::post('/{context}/{projectId}/{token}/ack', [TriggerInboxController::class, 'ack'])->where($where);
});
