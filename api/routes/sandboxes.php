<?php

use App\Http\Controllers\Api\Sandboxes\SandboxAdminController;
use App\Http\Controllers\Api\Sandboxes\SandboxesController;
use App\Http\Controllers\Api\Sandboxes\SandboxExecController;
use App\Http\Controllers\Api\Sandboxes\SandboxFilesController;
use App\Http\Controllers\Api\Sandboxes\SandboxProcessesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sandbox API (docs/sandbox-api.md)
|--------------------------------------------------------------------------
| Loaded by App\Providers\SandboxServiceProvider under the `api` group with auth:sanctum, prefix
| /api. Abilities (sandboxes.view / create / exec / manage) are checked in the controllers.
| WebSockets (Reverb): /ws/sandbox-exec?token=… (POST …/exec/ws) and the web shell
| /ws/shell?token=… (POST …/terminal).
*/

// Lifecycle
Route::get('/sandboxes', [SandboxesController::class, 'index'])->name('sandboxes.index');
Route::post('/sandboxes', [SandboxesController::class, 'store'])->name('sandboxes.store');
Route::get('/sandboxes/{sandbox}', [SandboxesController::class, 'show'])->name('sandboxes.show');
Route::delete('/sandboxes/{sandbox}', [SandboxesController::class, 'destroy'])->name('sandboxes.destroy');
Route::post('/sandboxes/{sandbox}/timeout', [SandboxesController::class, 'timeout'])->name('sandboxes.timeout');
Route::post('/sandboxes/{sandbox}/keepalive', [SandboxesController::class, 'keepalive'])->name('sandboxes.keepalive');
Route::post('/sandboxes/{sandbox}/pause', [SandboxesController::class, 'pause'])->name('sandboxes.pause');
Route::post('/sandboxes/{sandbox}/resume', [SandboxesController::class, 'resume'])->name('sandboxes.resume');
Route::put('/sandboxes/{sandbox}/network', [SandboxesController::class, 'network'])->name('sandboxes.network');
Route::get('/sandboxes/{sandbox}/logs', [SandboxesController::class, 'logs'])->name('sandboxes.logs');

// Exec (JSON / SSE), exec WebSocket token, code interpreter, terminal
Route::post('/sandboxes/{sandbox}/exec', [SandboxExecController::class, 'exec'])->name('sandboxes.exec');
Route::post('/sandboxes/{sandbox}/exec/ws', [SandboxExecController::class, 'execWs'])->name('sandboxes.exec.ws');
Route::post('/sandboxes/{sandbox}/run', [SandboxExecController::class, 'run'])->name('sandboxes.run');
Route::post('/sandboxes/{sandbox}/terminal', [SandboxExecController::class, 'terminal'])->name('sandboxes.terminal');

// Background processes
Route::get('/sandboxes/{sandbox}/processes', [SandboxProcessesController::class, 'index'])->name('sandboxes.processes.index');
Route::post('/sandboxes/{sandbox}/processes', [SandboxProcessesController::class, 'store'])->name('sandboxes.processes.store');
Route::get('/sandboxes/{sandbox}/processes/{process}', [SandboxProcessesController::class, 'show'])->name('sandboxes.processes.show');
Route::get('/sandboxes/{sandbox}/processes/{process}/logs', [SandboxProcessesController::class, 'logs'])->name('sandboxes.processes.logs');
Route::delete('/sandboxes/{sandbox}/processes/{process}', [SandboxProcessesController::class, 'destroy'])->name('sandboxes.processes.destroy');

// Files
Route::get('/sandboxes/{sandbox}/files', [SandboxFilesController::class, 'index'])->name('sandboxes.files.index');
Route::delete('/sandboxes/{sandbox}/files', [SandboxFilesController::class, 'destroy'])->name('sandboxes.files.destroy');
Route::get('/sandboxes/{sandbox}/files/content', [SandboxFilesController::class, 'read'])->name('sandboxes.files.read');
Route::put('/sandboxes/{sandbox}/files/content', [SandboxFilesController::class, 'write'])->name('sandboxes.files.write');
Route::post('/sandboxes/{sandbox}/files/mkdir', [SandboxFilesController::class, 'mkdir'])->name('sandboxes.files.mkdir');
Route::get('/sandboxes/{sandbox}/files/archive', [SandboxFilesController::class, 'archive'])->name('sandboxes.files.archive');
Route::put('/sandboxes/{sandbox}/files/archive', [SandboxFilesController::class, 'extract'])->name('sandboxes.files.extract');

// Region settings, warm pools, quotas, metrics, usage
Route::get('/sandbox-regions', [SandboxAdminController::class, 'regions'])->name('sandboxes.regions');
Route::put('/sandbox-regions/{knativeRegion}', [SandboxAdminController::class, 'updateRegion'])->middleware('region.mutable')->name('sandboxes.regions.update');
Route::get('/sandbox-pools', [SandboxAdminController::class, 'pools'])->name('sandboxes.pools.index');
Route::post('/sandbox-pools', [SandboxAdminController::class, 'storePool'])->name('sandboxes.pools.store');
Route::put('/sandbox-pools/{pool}', [SandboxAdminController::class, 'updatePool'])->name('sandboxes.pools.update');
Route::delete('/sandbox-pools/{pool}', [SandboxAdminController::class, 'destroyPool'])->name('sandboxes.pools.destroy');
Route::get('/sandbox-quotas', [SandboxAdminController::class, 'quota'])->name('sandboxes.quotas.show');
Route::get('/sandbox-quotas/tenants', [SandboxAdminController::class, 'quotaTenants'])->name('sandboxes.quotas.tenants');
Route::put('/sandbox-quotas/{tenant}', [SandboxAdminController::class, 'updateQuota'])->name('sandboxes.quotas.update');
Route::delete('/sandbox-quotas/{tenant}', [SandboxAdminController::class, 'destroyQuota'])->name('sandboxes.quotas.destroy');
Route::get('/sandbox-metrics', [SandboxAdminController::class, 'metrics'])->name('sandboxes.metrics');
Route::get('/sandbox-metrics/prometheus', [SandboxAdminController::class, 'prometheus'])->name('sandboxes.metrics.prometheus');
Route::get('/sandbox-usage', [SandboxAdminController::class, 'usage'])->name('sandboxes.usage');
