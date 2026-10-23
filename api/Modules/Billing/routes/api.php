<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\ArchivedBillController;
use Modules\Billing\Http\Controllers\BillController;
use Modules\Billing\Http\Controllers\EventController;
use Modules\Billing\Http\Controllers\FixedRequestController;
use Modules\Billing\Http\Controllers\LegacyRecordController;
use Modules\Billing\Http\Controllers\MarkupController;
use Modules\Billing\Http\Controllers\MyBillsController;
use Modules\Billing\Http\Controllers\PlatformBillController;
use Modules\Billing\Http\Controllers\PriceController;
use Modules\Billing\Http\Controllers\ProjectBillingController;
use Modules\Billing\Http\Controllers\RegionController;
use Modules\Billing\Http\Controllers\RegionOfferController;
use Modules\Billing\Http\Controllers\RollupController;
use Modules\Billing\Http\Controllers\SettingsController;
use Modules\Billing\Http\Controllers\TenantBillController;
use Modules\Billing\Http\Controllers\UsageController;

/*
| Billing API (docs/billing.md#api), loaded by BillingServiceProvider under the /api prefix.
| Authorized by config/route_permissions.php (billing.view reads, billing.manage writes,
| billing.approve approvals; the project routes additionally by ProjectBillingController).
| Every route works in the request's organization: the install, the platform or a tenant.
*/

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::prefix('billing')->group(function () {
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::put('/settings', [SettingsController::class, 'update']);

        Route::get('/prices', [PriceController::class, 'index']);
        Route::post('/prices', [PriceController::class, 'store']);
        Route::delete('/prices/{price}', [PriceController::class, 'destroy']);

        Route::get('/regions', [RegionController::class, 'index']);
        Route::put('/regions/{region}', [RegionController::class, 'update']);
        Route::put('/regions/{region}/queue-consumers', [RegionController::class, 'queueConsumers']);
        Route::get('/regions/{region}/organizations', [RegionController::class, 'organizations']);
        Route::put('/regions/{region}/organizations/{tenant}', [RegionController::class, 'organization']);

        Route::get('/markups', [MarkupController::class, 'index']);
        Route::post('/markups', [MarkupController::class, 'store']);
        Route::delete('/markups/{markup}', [MarkupController::class, 'destroy']);

        Route::get('/region-offers', [RegionOfferController::class, 'index']);
        Route::post('/region-offers/{region}/accept', [RegionOfferController::class, 'accept']);

        Route::get('/bills', [BillController::class, 'index']);
        Route::get('/bills/{bill}', [BillController::class, 'show']);
        Route::put('/bills/{bill}/payment-url', [BillController::class, 'paymentUrl']);
        Route::put('/bills/{bill}/paid', [BillController::class, 'paid']);

        Route::get('/usage', [UsageController::class, 'index']);
        Route::get('/rollup', [RollupController::class, 'index']);
        Route::get('/me', [MyBillsController::class, 'index']);

        Route::get('/fixed-requests', [FixedRequestController::class, 'index']);
        Route::post('/fixed-requests/{fixedRequest}/approve', [FixedRequestController::class, 'approve']);
        Route::post('/fixed-requests/{fixedRequest}/reject', [FixedRequestController::class, 'reject']);

        Route::get('/events', [EventController::class, 'index']);
        Route::post('/events/test', [EventController::class, 'test']);
        Route::post('/events/{event}/redeliver', [EventController::class, 'redeliver']);

        Route::get('/platform-bills', [PlatformBillController::class, 'index']);
        Route::get('/platform-bills/{bill}', [PlatformBillController::class, 'show']);

        Route::get('/tenant-bills', [TenantBillController::class, 'index']);
        Route::get('/tenant-bills/{bill}', [TenantBillController::class, 'show']);
        Route::put('/tenant-bills/{bill}/payment-url', [TenantBillController::class, 'paymentUrl']);
        Route::put('/tenant-bills/{bill}/paid', [TenantBillController::class, 'paid']);

        Route::get('/legacy-records', [LegacyRecordController::class, 'index']);

        Route::get('/archived-bills', [ArchivedBillController::class, 'index']);
        Route::get('/archived-bills/{bill}', [ArchivedBillController::class, 'show']);
    });

    Route::get('/projects/{project}/billing', [ProjectBillingController::class, 'show']);
    Route::get('/projects/{project}/billing/quote', [ProjectBillingController::class, 'quote']);
    Route::get('/projects/{project}/billing/bills/{bill}', [ProjectBillingController::class, 'bill']);
    Route::post('/projects/{project}/billing/fixed-requests', [ProjectBillingController::class, 'storeRequest']);
    Route::post('/projects/{project}/billing/fixed-requests/{fixedRequest}/cancel', [ProjectBillingController::class, 'cancel']);
    Route::get('/projects/{project}/billing/cost-limit', [ProjectBillingController::class, 'costLimit']);
    Route::put('/projects/{project}/billing/cost-limit', [ProjectBillingController::class, 'setCostLimit']);
    Route::delete('/projects/{project}/billing/cost-limit', [ProjectBillingController::class, 'clearCostLimit']);
    Route::post('/projects/{project}/billing/cost-limit/resume', [ProjectBillingController::class, 'resumeCostLimit']);
});
