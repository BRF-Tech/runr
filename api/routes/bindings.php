<?php

/*
|--------------------------------------------------------------------------
| Bindings (docs/bindings.md) — included by routes/api.php inside its auth:sanctum group
|--------------------------------------------------------------------------
| Permissions (config/permissions.php, rules in config/route_permissions.php): bindings.view,
| bindings.manage, bindings.view_credentials, regions.data_services.manage; project routes need the
| project permission on the route and bindings.manage organization-wide in the controller.
*/

use App\Http\Controllers\Api\BindingController;
use App\Http\Controllers\Api\DataServiceController;
use App\Http\Controllers\Api\ProjectBindingController;
use Illuminate\Support\Facades\Route;

// Organization bindings (static paths before {binding})
Route::get('/bindings/types', [BindingController::class, 'types']);
Route::get('/bindings/quota', [BindingController::class, 'quota']);
Route::get('/bindings', [BindingController::class, 'index']);
Route::post('/bindings', [BindingController::class, 'store']);
Route::get('/bindings/{binding}', [BindingController::class, 'show']);
Route::patch('/bindings/{binding}', [BindingController::class, 'update']);
Route::delete('/bindings/{binding}', [BindingController::class, 'destroy']);
Route::post('/bindings/{binding}/restore', [BindingController::class, 'restore']);
Route::post('/bindings/{binding}/rotate', [BindingController::class, 'rotate']);
Route::get('/bindings/{binding}/status', [BindingController::class, 'status']);
Route::get('/bindings/{binding}/credentials', [BindingController::class, 'credentials']);
Route::get('/bindings/{binding}/usage', [BindingController::class, 'usage']);

// Project attachments
Route::get('/projects/{project}/bindings', [ProjectBindingController::class, 'index']);
Route::post('/projects/{project}/bindings', [ProjectBindingController::class, 'store']);
Route::patch('/projects/{project}/bindings/{attachment}', [ProjectBindingController::class, 'update']);
Route::delete('/projects/{project}/bindings/{attachment}', [ProjectBindingController::class, 'destroy']);

// Region data services
Route::get('/knative-regions/{knativeRegion}/data-services', [DataServiceController::class, 'index']);
Route::post('/knative-regions/{knativeRegion}/data-services/{service}', [DataServiceController::class, 'store'])->middleware('region.mutable');
Route::delete('/knative-regions/{knativeRegion}/data-services/{service}', [DataServiceController::class, 'destroy'])->middleware('region.mutable');
