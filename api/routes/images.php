<?php

use App\Http\Controllers\Api\Images\ImageController;
use App\Http\Controllers\Api\Images\ImageUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Uploaded images (docs/images.md) — loaded by ImageServiceProvider
|--------------------------------------------------------------------------
| Under /api with `api`, `auth:sanctum` and no-store (live upload state). Abilities images.view /
| images.upload / images.delete are catalog permissions (config/permissions.php); the rules are
| in config/route_permissions.php. Uploads are the organization's: a user sees the uploads of the
| current tenant (or the platform's outside one).
*/

Route::get('/images', [ImageController::class, 'index']);
Route::get('/images/quota', [ImageController::class, 'quota']);
Route::get('/images/uploads', [ImageUploadController::class, 'index']);
Route::post('/images/uploads', [ImageUploadController::class, 'store']);
Route::get('/images/uploads/{upload}', [ImageUploadController::class, 'show'])->whereUlid('upload');
Route::put('/images/uploads/{upload}/chunk', [ImageUploadController::class, 'chunk'])->whereUlid('upload');
Route::post('/images/uploads/{upload}/complete', [ImageUploadController::class, 'complete'])->whereUlid('upload');
Route::delete('/images/uploads/{upload}', [ImageUploadController::class, 'destroy'])->whereUlid('upload');
Route::get('/images/{image}', [ImageController::class, 'show'])->whereUlid('image');
Route::delete('/images/{image}', [ImageController::class, 'destroy'])->whereUlid('image');
