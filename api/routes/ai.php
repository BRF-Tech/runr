<?php

use App\Http\Controllers\Api\Ai\AiModelController;
use App\Http\Controllers\Api\Ai\GpuController;
use App\Http\Controllers\Api\Ai\HubController;
use App\Http\Controllers\Api\Ai\ModelServiceController;
use App\Http\Controllers\Api\Ai\ModelSnapshotController;
use App\Http\Controllers\Api\Ai\ModelUploadController;
use App\Http\Controllers\Api\Ai\ProjectModelBindingController;
use App\Http\Middleware\NoStoreResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI routes — GPU support and Hugging Face models (docs/ai.md, docs/huggingface.md)
|--------------------------------------------------------------------------
| Loaded by App\Providers\AiServiceProvider under /api with the `api` and `auth:sanctum`
| middleware. Abilities (models.view / models.deploy / models.manage / regions.gpu.manage) are
| checked in the controllers; routes that change a region carry `region.mutable` as in
| routes/api.php.
*/

// ── GPU support of a region ─────────────────────────────────────────────────
Route::get('/knative-regions/{knativeRegion}/gpu', [GpuController::class, 'show']);
Route::post('/knative-regions/{knativeRegion}/gpu/install', [GpuController::class, 'install'])->middleware('region.mutable');
Route::post('/knative-regions/{knativeRegion}/gpu/uninstall', [GpuController::class, 'uninstall'])->middleware('region.mutable');
Route::put('/knative-regions/{knativeRegion}/gpu/settings', [GpuController::class, 'updateSettings'])->middleware('region.mutable');
// Live claim status (Pending → Bound): never answered from the response cache
Route::get('/knative-regions/{knativeRegion}/model-storage', [GpuController::class, 'storage'])->middleware(NoStoreResponse::class);
Route::put('/knative-regions/{knativeRegion}/model-storage', [GpuController::class, 'updateStorage'])->middleware('region.mutable');

// ── Hub browser, engines, token, GPU quota ──────────────────────────────────
Route::get('/models/engines', [HubController::class, 'engines']);
Route::get('/models/hub/search', [HubController::class, 'search']);
Route::get('/models/hub/info', [HubController::class, 'info']);
Route::get('/models/hub-token', [HubController::class, 'token']);
Route::put('/models/hub-token', [HubController::class, 'setToken']);
Route::delete('/models/hub-token', [HubController::class, 'deleteToken']);
Route::get('/models/gpu-quota', [HubController::class, 'gpuQuota']);

// ── Models, snapshots (offline copies), uploads (air-gapped) ────────────────
Route::get('/models', [AiModelController::class, 'index']);
Route::post('/models', [AiModelController::class, 'store']);
Route::get('/models/{model}', [AiModelController::class, 'show'])->whereUlid('model');
Route::patch('/models/{model}', [AiModelController::class, 'update'])->whereUlid('model');
Route::delete('/models/{model}', [AiModelController::class, 'destroy'])->whereUlid('model');

Route::scopeBindings()->group(function () {
    Route::get('/models/{model}/snapshots', [ModelSnapshotController::class, 'index'])->whereUlid('model');
    Route::post('/models/{model}/snapshots', [ModelSnapshotController::class, 'store'])->whereUlid('model');
    Route::delete('/models/{model}/snapshots/{snapshot}', [ModelSnapshotController::class, 'destroy'])->whereUlid('model');
    Route::get('/models/{model}/snapshots/{snapshot}/logs', [ModelSnapshotController::class, 'logs'])->whereUlid('model');

    Route::post('/models/{model}/uploads', [ModelUploadController::class, 'store'])->whereUlid('model');
    Route::get('/models/{model}/uploads/{upload}', [ModelUploadController::class, 'show'])->whereUlid('model');
    Route::put('/models/{model}/uploads/{upload}/chunk', [ModelUploadController::class, 'chunk'])->whereUlid('model');
    Route::post('/models/{model}/uploads/{upload}/complete', [ModelUploadController::class, 'complete'])->whereUlid('model');
    Route::delete('/models/{model}/uploads/{upload}', [ModelUploadController::class, 'destroy'])->whereUlid('model');
});

// ── Model services ──────────────────────────────────────────────────────────
Route::get('/model-services', [ModelServiceController::class, 'index']);
Route::post('/model-services', [ModelServiceController::class, 'store']);
Route::get('/model-services/{service}', [ModelServiceController::class, 'show']);
Route::patch('/model-services/{service}', [ModelServiceController::class, 'update']);
Route::delete('/model-services/{service}', [ModelServiceController::class, 'destroy']);
Route::post('/model-services/{service}/deploy', [ModelServiceController::class, 'deploy']);
Route::post('/model-services/{service}/stop', [ModelServiceController::class, 'stop']);
Route::get('/model-services/{service}/logs', [ModelServiceController::class, 'logs']);
Route::post('/model-services/{service}/invoke', [ModelServiceController::class, 'invoke'])->middleware('throttle:60,1');
Route::get('/model-services/{service}/bindings', [ModelServiceController::class, 'bindings']);

// ── Models of a project (bindings: RUNR_MODEL_<ALIAS>_* env at deploy) ───────
Route::scopeBindings()->group(function () {
    Route::get('/projects/{project}/models', [ProjectModelBindingController::class, 'index']);
    Route::post('/projects/{project}/models', [ProjectModelBindingController::class, 'store']);
    Route::patch('/projects/{project}/models/{modelBinding}', [ProjectModelBindingController::class, 'update']);
    Route::delete('/projects/{project}/models/{modelBinding}', [ProjectModelBindingController::class, 'destroy']);
});
