<?php

use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Middleware\NoStoreResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Project environments and promotion (docs/environments.md) — loaded by EnvironmentServiceProvider
|--------------------------------------------------------------------------
|
| {environment} is an environment's name or id ("production": the project's own workload).
| no-store: live state the console polls, env override values (ResponseCache never keeps them).
*/

Route::middleware(['api', 'auth:sanctum', NoStoreResponse::class])->prefix('api')->group(function () {
    Route::get('/projects/{project}/environments', [EnvironmentController::class, 'index']);
    Route::post('/projects/{project}/environments', [EnvironmentController::class, 'store']);
    Route::get('/projects/{project}/environments/{environment}', [EnvironmentController::class, 'show']);
    Route::patch('/projects/{project}/environments/{environment}', [EnvironmentController::class, 'update']);
    Route::delete('/projects/{project}/environments/{environment}', [EnvironmentController::class, 'destroy']);

    Route::get('/projects/{project}/environments/{environment}/secrets', [EnvironmentController::class, 'secrets']);
    Route::put('/projects/{project}/environments/{environment}/secrets/{name}', [EnvironmentController::class, 'setSecret']);
    Route::delete('/projects/{project}/environments/{environment}/secrets/{name}', [EnvironmentController::class, 'deleteSecret']);

    Route::get('/projects/{project}/environments/{environment}/promote', [PromotionController::class, 'preview']);
    Route::post('/projects/{project}/environments/{environment}/promote', [PromotionController::class, 'promote']);
    Route::get('/projects/{project}/promotions', [PromotionController::class, 'index']);
    Route::post('/projects/{project}/promotions/{promotion}/approve', [PromotionController::class, 'approve']);
    Route::post('/projects/{project}/promotions/{promotion}/reject', [PromotionController::class, 'reject']);
    Route::post('/projects/{project}/promotions/{promotion}/cancel', [PromotionController::class, 'cancel']);

    // The approvals inbox: promotions waiting for an approver, across the user's projects
    Route::get('/environment-promotions', [PromotionController::class, 'inbox']);
});
