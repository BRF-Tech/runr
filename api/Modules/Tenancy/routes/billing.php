<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\Billing\BillingEventController;
use Modules\Tenancy\Http\Controllers\Billing\BillingTenantController;
use Modules\Tenancy\Http\Controllers\Billing\ChargeController;
use Modules\Tenancy\Http\Controllers\Billing\PurchaseController;
use Modules\Tenancy\Http\Controllers\Billing\UsageController;
use Modules\Tenancy\Http\Controllers\WhiteLabel\PlanController;

/*
| Billing API (docs/billing.md): what an external billing / invoicing system uses — usage, charges,
| plan changes, purchases, events. Loaded by PlanServiceProvider under "api" + "auth:sanctum" and
| the /api prefix. Platform administrators; their API tokens need billing.view (reads) or
| billing.manage (writes) — '@platform:billing.*' rules in config/route_permissions.php.
*/

Route::prefix('billing')->name('billing.')->group(function () {
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');

    Route::get('/tenants', [BillingTenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/{tenant}', [BillingTenantController::class, 'show'])->name('tenants.show');
    Route::get('/tenants/{tenant}/usage', [UsageController::class, 'show'])->name('tenants.usage');
    Route::get('/tenants/{tenant}/subscription', [BillingTenantController::class, 'subscription'])->name('tenants.subscription.show');
    Route::put('/tenants/{tenant}/subscription', [BillingTenantController::class, 'updateSubscription'])->name('tenants.subscription.update');
    Route::post('/tenants/{tenant}/charges', [ChargeController::class, 'store'])->name('tenants.charges.store');
    Route::post('/tenants/{tenant}/purchases', [PurchaseController::class, 'store'])->name('tenants.purchases.store');

    Route::get('/charges', [ChargeController::class, 'index'])->name('charges.index');
    Route::get('/charges/{charge}', [ChargeController::class, 'show'])->name('charges.show');
    Route::patch('/charges/{charge}', [ChargeController::class, 'update'])->name('charges.update');
    Route::post('/charges/{charge}/void', [ChargeController::class, 'void'])->name('charges.void');

    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
    Route::post('/purchases/{purchase}/void', [PurchaseController::class, 'void'])->name('purchases.void');

    Route::get('/events', [BillingEventController::class, 'index'])->name('events.index');
    Route::post('/events/test', [BillingEventController::class, 'test'])->name('events.test');
    Route::post('/events/{event}/redeliver', [BillingEventController::class, 'redeliver'])->name('events.redeliver');
});
