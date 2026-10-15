<?php

namespace Modules\Tenancy\Http\Controllers\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Tenancy\Http\Controllers\WhiteLabel\Concerns\ResolvesTenantContext;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Services\Plans\PlanLimitService;

/**
 * The active tenant's plan (Settings → Subscription), readable by every member:
 *
 *  GET /api/tenant/subscription   plan, period, effective limits and their usage, public plans;
 *                                 for billing.manage the operator's store URL (where plans and
 *                                 credits are bought)
 *
 * Plans are put into effect by the platform administrator or the operator's billing system
 * (billing API, docs/billing.md).
 */
class TenantSubscriptionController extends Controller
{
    use ResolvesTenantContext;

    public function __construct(
        private readonly BillingSettings $settings,
        private readonly PlanLimitService $limits,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenantOf($request);
        $subscription = TenantSubscription::findForTenant((string) $tenant->id);
        $plansOn = $this->settings->plansEnabled();
        $plan = $this->limits->planFor($tenant);

        return response()->json([
            'plans_enabled' => $plansOn,
            'subscription' => $subscription?->toApiArray(),
            'limits' => $plan ? $this->limits->effectiveLimits($tenant, $plan) : null,
            'addon_limits' => (object) $this->limits->addonLimits($tenant),
            'plans' => $plansOn
                ? Plan::query()->public()->orderBy('sort_order')->orderBy('price_monthly')->get()->map->toPublicArray()->values()
                : [],
            'usage' => $this->limits->usage($tenant),
            'store_url' => Gate::allows('billing.manage') ? ($this->settings->storeUrl() ?: null) : null,
        ]);
    }
}
