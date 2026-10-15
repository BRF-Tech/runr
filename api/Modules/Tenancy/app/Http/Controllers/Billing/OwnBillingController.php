<?php

namespace Modules\Tenancy\Http\Controllers\Billing;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Tenancy\Http\Controllers\WhiteLabel\Concerns\ResolvesTenantContext;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Services\Billing\ChargeService;
use Modules\Tenancy\Services\Billing\CreditLedger;

/**
 * The active tenant's own charges, credit and purchases (Settings → Billing in the tenant;
 * billing.view — owners, admins and the Billing role):
 *
 *  GET /api/tenant/billing   charges (newest first; open ones with their amounts as of now), credit
 *                            balances, purchases; for billing.manage the operator's store URL
 */
class OwnBillingController extends Controller
{
    use ResolvesTenantContext;

    /** Charges listed (newest periods first) */
    private const LIMIT = 60;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly CreditLedger $credits,
        private readonly BillingSettings $settings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenantOf($request);
        $tenantId = (string) $tenant->id;

        $charges = Charge::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', Charge::STATUS_VOID)
            ->orderByDesc('period_start')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'charges' => $charges->map(fn (Charge $c) => $this->charges->present($c))->values(),
            'credit_balances' => (object) $this->credits->balances($tenantId),
            'purchases' => Purchase::query()->where('tenant_id', $tenantId)->with('plan')
                ->orderByDesc('purchased_at')->limit(self::LIMIT)->get()
                ->map(fn (Purchase $p) => array_diff_key($p->toApiArray(), ['metadata' => true]))->values(),
            'store_url' => Gate::allows('billing.manage') ? ($this->settings->storeUrl() ?: null) : null,
        ]);
    }
}
