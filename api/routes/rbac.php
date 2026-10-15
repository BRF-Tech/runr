<?php

/*
| Roles & permissions API (docs/permissions.md). Loaded inside the auth:sanctum group of
| routes/api.php; what each route requires: config/route_permissions.php.
*/

use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProjectAccessController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/permissions', [PermissionController::class, 'mine']);
Route::get('/permissions', [PermissionController::class, 'catalog']);

Route::get('/roles', [RoleController::class, 'index']);
Route::post('/roles', [RoleController::class, 'store']);
Route::put('/roles/default', [RoleController::class, 'setDefault']);
Route::get('/roles/{role}', [RoleController::class, 'show']);
Route::put('/roles/{role}', [RoleController::class, 'update']);
Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

Route::get('/groups', [GroupController::class, 'index']);
Route::post('/groups', [GroupController::class, 'store']);
Route::get('/groups/{group}', [GroupController::class, 'show']);
Route::put('/groups/{group}', [GroupController::class, 'update']);
Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
Route::post('/groups/{group}/members', [GroupController::class, 'addMembers']);
Route::delete('/groups/{group}/members/{user}', [GroupController::class, 'removeMember']);

Route::get('/members', [MemberController::class, 'index']);
Route::put('/members/{user}/role', [MemberController::class, 'updateRole']);

Route::get('/projects/{project}/access', [ProjectAccessController::class, 'index']);
Route::post('/projects/{project}/access', [ProjectAccessController::class, 'store']);
Route::delete('/projects/{project}/access/{assignment}', [ProjectAccessController::class, 'destroy']);
