<?php

use App\Http\Controllers\Api\WhiteLabel\BrandingController;
use App\Http\Controllers\Api\WhiteLabel\SignupController;
use Illuminate\Support\Facades\Route;

/*
| White-label (docs/white-label.md) — loaded by App\Providers\WhiteLabelServiceProvider under
| the "api" middleware group and the /api prefix. Public routes stay outside auth:sanctum; the
| authenticated ones are authorized by config/route_permissions.php (branding.manage, signup.manage).
*/

$assetKinds = ['logo_light', 'logo_dark', 'logo_mark', 'favicon', 'email_logo'];

// Public: branded login page before sign-in, brand images, e-mail verification of signups
Route::get('/branding', [BrandingController::class, 'show'])->name('branding.show');
Route::get('/branding/assets/{kind}', [BrandingController::class, 'asset'])
    ->whereIn('kind', $assetKinds)->middleware('throttle:120,1')->name('branding.asset');
Route::post('/auth/signup/verify', [SignupController::class, 'verify'])->middleware('throttle:auth')->name('signup.verify');
Route::post('/auth/signup/resend', [SignupController::class, 'resend'])->middleware('throttle:auth')->name('signup.resend');

// The install's branding and signup settings
Route::middleware('auth:sanctum')->prefix('admin')->group(function () use ($assetKinds) {
    Route::get('/branding', [BrandingController::class, 'index'])->name('admin.branding.show');
    Route::put('/branding', [BrandingController::class, 'update'])->name('admin.branding.update');
    Route::post('/branding/assets/{kind}', [BrandingController::class, 'uploadAsset'])
        ->whereIn('kind', $assetKinds)->name('admin.branding.assets.store');
    Route::delete('/branding/assets/{kind}', [BrandingController::class, 'deleteAsset'])
        ->whereIn('kind', $assetKinds)->name('admin.branding.assets.destroy');

    Route::get('/signup-settings', [SignupController::class, 'settings'])->name('admin.signup.show');
    Route::put('/signup-settings', [SignupController::class, 'updateSettings'])->name('admin.signup.update');
});
