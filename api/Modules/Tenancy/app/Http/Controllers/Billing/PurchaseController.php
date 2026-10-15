<?php

namespace Modules\Tenancy\Http\Controllers\Billing;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Http\Controllers\Billing\Concerns\ResolvesBillingTenant;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Billing\CreditLedger;
use Modules\Tenancy\Services\Billing\PurchaseService;

/**
 * Billing API — purchases made in an external system (docs/billing.md). Platform administrators and
 * API tokens with billing.view (reads) / billing.manage (writes).
 *
 *  GET  /api/billing/purchases                     ?tenant= &kind= &per_page=
 *  GET  /api/billing/purchases/{purchase}
 *  POST /api/billing/tenants/{tenant}/purchases    {kind: credit|plan|addon, external_ref, …} → 201, or 200 with the
 *                                                  purchase already recorded under that external_ref
 *  POST /api/billing/purchases/{purchase}/void     reversed in the external system
 */
class PurchaseController extends Controller
{
    use ResolvesBillingTenant;

    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly CreditLedger $credits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tenant' => ['sometimes', 'string', 'max:64'],
            'kind' => ['sometimes', Rule::in(Purchase::KINDS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Purchase::query()->with('plan');
        if ($tenant = $request->query('tenant')) {
            $query->where('tenant_id', (string) $this->billingTenant((string) $tenant)->id);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        $page = $query->orderByDesc('purchased_at')->orderByDesc('id')->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Purchase $p) => $p->toApiArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $purchase): JsonResponse
    {
        return response()->json(['purchase' => Purchase::query()->findOrFail($purchase)->toApiArray()]);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        $model = $this->billingTenant($tenant);
        $data = $request->validate([
            'kind' => ['required', Rule::in(Purchase::KINDS)],
            'external_ref' => ['required', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['required_if:kind,credit', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'currency' => ['required_if:kind,credit', 'nullable', 'string', 'size:3', 'alpha'],
            'plan' => ['required_if:kind,plan', 'nullable', 'string', 'max:64'],
            'interval' => ['sometimes', 'nullable', Rule::in(Plan::INTERVALS)],
            'limits' => ['required_if:kind,addon', 'nullable', 'array'],
            'limits.*' => ['integer', 'min:0'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'purchased_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
            'metadata.*' => ['nullable', 'string', 'max:500'],
        ]);

        [$purchase, $created] = $this->purchases->record($model, $data);

        return response()->json([
            'purchase' => $purchase->fresh()->toApiArray(),
            'subscription' => TenantSubscription::findForTenant((string) $model->id)?->toApiArray(),
            'credit_balances' => (object) $this->credits->balances((string) $model->id),
        ], $created ? 201 : 200);
    }

    public function void(string $purchase): JsonResponse
    {
        $model = $this->purchases->void(Purchase::query()->findOrFail($purchase));

        return response()->json([
            'purchase' => $model->toApiArray(),
            'credit_balances' => (object) $this->credits->balances((string) $model->tenant_id),
        ]);
    }
}
