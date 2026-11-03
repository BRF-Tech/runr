<?php

use App\Http\Controllers\Api\Captcha\CaptchaController;
use Illuminate\Support\Facades\Route;

/*
| Bot protection (docs/security.md#bot-protection) — loaded by App\Providers\CaptchaServiceProvider
| under the "api" middleware group and the /api prefix. The organizations' own keys
| (/api/tenant/captcha) are in Modules/Tenancy/routes/white-label.php.
*/

// Public: what the forms need before sign-in; a local challenge when the widget cannot load
Route::get('/captcha/config', [CaptchaController::class, 'config'])->middleware('throttle:120,1')->name('captcha.config');
Route::post('/captcha/local/challenge', [CaptchaController::class, 'challenge'])->middleware('throttle:30,1')->name('captcha.local.challenge');

// The platform's settings (Settings → Access & security → Bot protection) and the fallback counter
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/captcha', [CaptchaController::class, 'show'])->name('admin.captcha.show');
    Route::put('/captcha', [CaptchaController::class, 'update'])->name('admin.captcha.update');
    Route::get('/captcha/metrics', [CaptchaController::class, 'metrics'])->name('admin.captcha.metrics');
});
