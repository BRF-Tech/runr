<?php

use App\Workflows\Engine\Definition;
use App\Workflows\Http\Controllers\WorkflowController;
use App\Workflows\Http\Controllers\WorkflowEventController;
use App\Workflows\Http\Controllers\WorkflowQueueHookController;
use App\Workflows\Http\Controllers\WorkflowRunController;
use App\Workflows\Http\Controllers\WorkflowUsageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Durable workflows API (docs/workflows.md) — loaded by WorkflowServiceProvider under
| the `api` middleware group and the /api prefix.
|--------------------------------------------------------------------------
*/

$workflowName = trim(Definition::NAME_PATTERN, '/^$');

Route::middleware('auth:sanctum')->group(function () use ($workflowName) {
    // Definitions + workflow app (static segments before {workflow})
    Route::get('/projects/{project}/workflows', [WorkflowController::class, 'index']);
    Route::put('/projects/{project}/workflows', [WorkflowController::class, 'declare']);
    Route::post('/projects/{project}/workflows/sync', [WorkflowController::class, 'sync']);
    Route::get('/projects/{project}/workflows/app', [WorkflowController::class, 'app']);
    Route::patch('/projects/{project}/workflows/app', [WorkflowController::class, 'updateApp']);
    Route::post('/projects/{project}/workflows/app/rotate-key', [WorkflowController::class, 'rotateKey']);
    Route::get('/projects/{project}/workflows/app/signing-key', [WorkflowController::class, 'signingKey']);

    Route::get('/projects/{project}/workflows/{workflow}', [WorkflowController::class, 'show'])->where('workflow', $workflowName);
    Route::patch('/projects/{project}/workflows/{workflow}', [WorkflowController::class, 'update'])->where('workflow', $workflowName);
    // One definition: declared (added or replaced) or archived, the others as they are
    Route::put('/projects/{project}/workflows/{workflow}', [WorkflowController::class, 'declareOne'])->where('workflow', $workflowName);
    Route::delete('/projects/{project}/workflows/{workflow}', [WorkflowController::class, 'archive'])->where('workflow', $workflowName);
    Route::get('/projects/{project}/workflows/{workflow}/runs', [WorkflowRunController::class, 'index'])->where('workflow', $workflowName);
    Route::post('/projects/{project}/workflows/{workflow}/runs', [WorkflowRunController::class, 'start'])->where('workflow', $workflowName);

    // Runs
    Route::get('/projects/{project}/workflow-runs', [WorkflowRunController::class, 'index']);
    Route::get('/projects/{project}/workflow-runs/{run}', [WorkflowRunController::class, 'show']);
    Route::post('/projects/{project}/workflow-runs/{run}/cancel', [WorkflowRunController::class, 'cancel']);
    Route::post('/projects/{project}/workflow-runs/{run}/retry', [WorkflowRunController::class, 'retry']);
    Route::post('/projects/{project}/workflow-runs/{run}/replay', [WorkflowRunController::class, 'replay']);

    // Usage
    Route::get('/projects/{project}/workflow-usage', [WorkflowUsageController::class, 'project']);
    Route::get('/workflow-usage', [WorkflowUsageController::class, 'tenant']);

    // Events (tenant level)
    Route::post('/events', [WorkflowEventController::class, 'store'])->middleware('throttle:600,1');
    Route::get('/events', [WorkflowEventController::class, 'index']);
    Route::get('/events/{event}', [WorkflowEventController::class, 'show']);
});

// Queue bridge sink (signed path token; api/internal/* is outside the `api` rate limiter); an
// environment's bridge names the environment (docs/workflows.md#environments)
Route::post('/internal/workflows/queue/{context}/{projectId}/{token}', [WorkflowQueueHookController::class, 'store'])
    ->where(['context' => '[A-Za-z0-9_-]{1,64}', 'projectId' => '[0-9A-Za-z]{26}', 'token' => '[0-9a-f]{40}']);
Route::post('/internal/workflows/queue/{context}/{projectId}/{environmentId}/{token}', [WorkflowQueueHookController::class, 'storeForEnvironment'])
    ->where(['context' => '[A-Za-z0-9_-]{1,64}', 'projectId' => '[0-9A-Za-z]{26}', 'environmentId' => '[0-9A-Za-z]{26}', 'token' => '[0-9a-f]{40}']);
