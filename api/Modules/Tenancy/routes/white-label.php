<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\Billing\OwnBillingController;
use Modules\Tenancy\Http\Controllers\WhiteLabel\BillingSettingsController;
use Modules\Tenancy\Http\Controllers\WhiteLabel\OnboardingController;
use Modules\Tenancy\Http\Controllers\WhiteLabel\PlanController;
use Modules\Tenancy\Http\Controllers\WhiteLabel\TenantBrandingController;
use Modules\Tenancy\Http\Controllers\WhiteLabel\TenantSubscriptionController;

/*
| White-label, multi-tenant part (docs/white-label.md, docs/billing.md) — loaded by
| PlanServiceProvider under the "api" + "auth:sanctum" middleware and the /api prefix; authorized
| by config/route_permissions.php.
*/

// Platform admin (@platform): plans, billing settings
Route::prefix('admin')->group(function () {
    Route::get('/plans', [PlanController::class, 'index'])->name('admin.plans.index');
    Route::post('/plans', [PlanController::class, 'store'])->name('admin.plans.store');
    Route::patch('/plans/{plan}', [PlanController::class, 'update'])->name('admin.plans.update');
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('admin.plans.destroy');

    Route::get('/billing-settings', [BillingSettingsController::class, 'show'])->name('admin.billing-settings.show');
    Route::put('/billing-settings', [BillingSettingsController::class, 'update'])->name('admin.billing-settings.update');
});

// Active tenant: its plan, its charges / credit / purchases
Route::get('/tenant/subscription', [TenantSubscriptionController::class, 'show'])->name('tenant.subscription.show');
Route::get('/tenant/billing', [OwnBillingController::class, 'show'])->name('tenant.billing.show');

// Self-service tenant onboarding wizard
Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
Route::post('/onboarding', [OnboardingController::class, 'complete'])->name('onboarding.complete');

// The tenant's own branding override
Route::get('/tenant/branding', [TenantBrandingController::class, 'show'])->name('tenant.branding.show');
Route::put('/tenant/branding', [TenantBrandingController::class, 'update'])->name('tenant.branding.update');
Route::post('/tenant/branding/assets/{kind}', [TenantBrandingController::class, 'uploadAsset'])
    ->whereIn('kind', ['logo_light', 'logo_dark', 'logo_mark', 'favicon', 'email_logo'])->name('tenant.branding.assets.store');
Route::delete('/tenant/branding/assets/{kind}', [TenantBrandingController::class, 'deleteAsset'])
    ->whereIn('kind', ['logo_light', 'logo_dark', 'logo_mark', 'favicon', 'email_logo'])->name('tenant.branding.assets.destroy');
