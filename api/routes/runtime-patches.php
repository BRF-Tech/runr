<?php

use App\Http\Controllers\Api\ProjectRuntimePatchController;
use App\Http\Controllers\Api\RuntimePatchAdminController;
use App\Http\Controllers\Api\RuntimePatchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Runtime patches (docs/runtimes.md#runtime-patches) — inside auth:sanctum (routes/api.php)
|--------------------------------------------------------------------------
| Permissions: config/route_permissions.php; audited: App\Services\Audit\AuditCatalog.
*/

Route::get('/runtime-patches', [RuntimePatchController::class, 'index']);
Route::get('/runtime-patches/banners', [RuntimePatchController::class, 'banners']);

Route::get('/projects/{project}/runtime-patches', [ProjectRuntimePatchController::class, 'show']);
Route::post('/projects/{project}/runtime-patches/apply', [ProjectRuntimePatchController::class, 'apply']);
Route::put('/projects/{project}/runtime-patches/pin', [ProjectRuntimePatchController::class, 'pin']);
Route::delete('/projects/{project}/runtime-patches/pin', [ProjectRuntimePatchController::class, 'unpin']);

// Platform administrators: the Patches page
Route::get('/admin/runtime-patches', [RuntimePatchAdminController::class, 'index']);
Route::post('/admin/runtime-patches/check', [RuntimePatchAdminController::class, 'check']);
Route::put('/admin/runtime-patches/policy', [RuntimePatchAdminController::class, 'policy']);
Route::post('/admin/runtime-patches/{patch}/remind', [RuntimePatchAdminController::class, 'remind']);
Route::post('/admin/runtime-patches/{patch}/force', [RuntimePatchAdminController::class, 'force']);
Route::put('/admin/runtime-patches/{patch}/deadline', [RuntimePatchAdminController::class, 'deadline']);
