<?php

use App\Http\Controllers\Api\OrgTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Organization export / import (docs/org-export.md) — loaded by OrgTransferServiceProvider
|--------------------------------------------------------------------------
| Under /api with `api`, `auth:sanctum` and no-store (live progress). Every route needs
| `org.transfer` (config/route_permissions.php) and the organization's Owner role (the
| controller). Transfers are the organization's: a user sees those of the current tenant (or of
| the installation outside one).
*/

Route::get('/org-transfers', [OrgTransferController::class, 'index']);
Route::post('/org-transfers/export/estimate', [OrgTransferController::class, 'estimate']);
Route::post('/org-transfers/export', [OrgTransferController::class, 'export']);
Route::post('/org-transfers/import', [OrgTransferController::class, 'import']);
Route::get('/org-transfers/{transfer}', [OrgTransferController::class, 'show'])->whereUlid('transfer');
Route::get('/org-transfers/{transfer}/download', [OrgTransferController::class, 'download'])->whereUlid('transfer');
Route::delete('/org-transfers/{transfer}', [OrgTransferController::class, 'destroy'])->whereUlid('transfer');
Route::put('/org-transfers/{transfer}/chunk', [OrgTransferController::class, 'chunk'])->whereUlid('transfer');
Route::post('/org-transfers/{transfer}/complete', [OrgTransferController::class, 'complete'])->whereUlid('transfer');
Route::post('/org-transfers/{transfer}/fetch', [OrgTransferController::class, 'fetch'])->whereUlid('transfer');
Route::get('/org-transfers/{transfer}/history', [OrgTransferController::class, 'history'])->whereUlid('transfer');
Route::post('/org-transfers/{transfer}/plan', [OrgTransferController::class, 'plan'])->whereUlid('transfer');
Route::post('/org-transfers/{transfer}/apply', [OrgTransferController::class, 'apply'])->whereUlid('transfer');
