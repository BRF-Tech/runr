<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\WhiteLabel\OnboardingController;
use Modules\Tenancy\Http\Controllers\WhiteLabel\TenantBrandingController;

/*
| White-label, multi-tenant part (docs/white-label.md) — loaded by TenantWhiteLabelServiceProvider
| under the "api" + "auth:sanctum" middleware and the /api prefix; authorized by
| config/route_permissions.php. Billing is the Billing module (docs/billing.md).
*/

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
