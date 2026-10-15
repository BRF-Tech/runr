<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantBillingService;

class TenantBillingController extends Controller
{
    public function __construct(
        protected TenantBillingService $billingService
    ) {}

    /**
     * Get billing summary for a tenant.
     */
    public function summary(string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $summary = $tenant->run(
            fn () => $this->billingService->getSummary($tenant)
        );

        return response()->json($summary);
    }

    /**
     * Get usage history for a tenant.
     */
    public function usage(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $days = (int) $request->query('days', 30);

        $history = $tenant->run(
            fn () => $this->billingService->getUsageHistory($tenant, $days)
        );

        return response()->json($history);
    }
}
