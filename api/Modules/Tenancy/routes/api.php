<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\CapacityDealController;
use Modules\Tenancy\Http\Controllers\CostConfigController;
use Modules\Tenancy\Http\Controllers\ImpersonateController;
use Modules\Tenancy\Http\Controllers\ResourceUsageController;
use Modules\Tenancy\Http\Controllers\TenancyUserController;
use Modules\Tenancy\Http\Controllers\TenantBillingController;
use Modules\Tenancy\Http\Controllers\TenantBindingQuotaController;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Http\Controllers\TenantGpuQuotaController;
use Modules\Tenancy\Http\Controllers\TenantUserController;

// Tenant management routes (platform admin only)
Route::middleware('tenancy.admin')->prefix('tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index']);
    Route::post('/', [TenantController::class, 'store']);
    Route::get('/{tenant}', [TenantController::class, 'show']);
    Route::patch('/{tenant}', [TenantController::class, 'update']);
    Route::delete('/{tenant}', [TenantController::class, 'destroy']);

    // Tenant regions (quota assignment — platform admin only)
    Route::get('/{tenant}/regions', [TenantController::class, 'regions']);
    Route::post('/{tenant}/regions', [TenantController::class, 'assignRegion']);
    Route::patch('/{tenant}/regions/{region}', [TenantController::class, 'updateRegionQuota']);
    // GPU quota of the tenant on the region (docs/ai.md#quotas-and-billing)
    Route::put('/{tenant}/regions/{region}/gpu-quota', [TenantGpuQuotaController::class, 'update']);
    Route::delete('/{tenant}/regions/{region}', [TenantController::class, 'removeRegion']);
});

// Tenant user management (inline auth: tenant admin/owner OR platform admin)
Route::prefix('tenants')->group(function () {
    Route::get('/{tenant}/users', [TenantUserController::class, 'index']);
    Route::post('/{tenant}/users', [TenantUserController::class, 'store']);
    Route::post('/{tenant}/users/search', [TenantUserController::class, 'searchEmail']);
    Route::patch('/{tenant}/users/{user}', [TenantUserController::class, 'update']);
    Route::delete('/{tenant}/users/{user}', [TenantUserController::class, 'destroy']);

    Route::post('/{tenant}/users/{user}/reset-2fa', [TenantUserController::class, 'reset2FA']);

    Route::post('/{tenant}/invitations/{invitation}/resend', [TenantUserController::class, 'resendInvitation']);
    Route::delete('/{tenant}/invitations/{invitation}', [TenantUserController::class, 'cancelInvitation']);
});

// Admin toggle + 2FA reset on /users endpoint (platform admin only)
Route::middleware('tenancy.admin')->group(function () {
    Route::post('/users/{user}/toggle-admin', [TenancyUserController::class, 'toggleAdmin']);
    Route::post('/users/{user}/reset-2fa', [TenancyUserController::class, 'reset2FA']);
});

// Resource usage (central admin overview)
Route::middleware('tenancy.admin')->group(function () {
    Route::get('/resource-usage', [ResourceUsageController::class, 'index']);
});

// Impersonation (admin only)
Route::middleware('tenancy.admin')->group(function () {
    Route::post('/impersonate/stop', [ImpersonateController::class, 'stop']);
    Route::post('/impersonate/{tenant}', [ImpersonateController::class, 'start']);
});

// Usage pricing, capacity deals, binding limits (platform admin; charges: routes/billing.php)
Route::middleware('tenancy.admin')->group(function () {
    Route::get('/cost-configs', [CostConfigController::class, 'index']);
    Route::post('/cost-configs', [CostConfigController::class, 'store']);
    Route::patch('/cost-configs/{id}', [CostConfigController::class, 'update']);
    Route::delete('/cost-configs/{id}', [CostConfigController::class, 'destroy']);

    Route::get('/tenants/{tenant}/billing/summary', [TenantBillingController::class, 'summary']);
    Route::get('/tenants/{tenant}/billing/usage', [TenantBillingController::class, 'usage']);

    Route::get('/tenants/{tenant}/capacity-deals', [CapacityDealController::class, 'index']);
    Route::post('/tenants/{tenant}/capacity-deals', [CapacityDealController::class, 'store']);
    Route::patch('/tenants/{tenant}/capacity-deals/{deal}', [CapacityDealController::class, 'update']);

    Route::get('/regions/{region}/profit-loss', [CapacityDealController::class, 'profitLoss']);

    // Binding limits of a tenant (docs/bindings.md "Quotas")
    Route::get('/tenants/{tenant}/binding-quotas', [TenantBindingQuotaController::class, 'show']);
    Route::put('/tenants/{tenant}/binding-quotas', [TenantBindingQuotaController::class, 'update']);
});

// Current tenant info (any authenticated user)
Route::get('/tenant/current', [TenantController::class, 'current']);
Route::post('/tenant/switch', [TenantController::class, 'switchTenant']);
