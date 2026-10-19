<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\Bill;
use Modules\Billing\Services\BillService;
use Modules\Billing\Support\BillingContext;

/**
 * The signed-in user's own bills in the organization (docs/billing.md#bills):
 *
 *  GET /api/billing/me   ?period=YYYY-MM (default: this month) — the bills of the projects they own
 *                        and their account bill, with line items
 */
class MyBillsController extends Controller
{
    use BillingRequests;

    public function __construct(private readonly BillService $bills) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $period = $this->period($request);
        if ($period->isCurrent()) {
            $this->bills->ensureOpenBills($period);
        }

        $costs = $this->canViewCosts($request);
        $bills = $this->bills->query()
            ->where('period', $period->key())
            ->where('owner_id', (string) $request->user()->getAuthIdentifier())
            ->orderBy('kind')->orderBy('project_name')
            ->get()
            ->map(fn (Bill $b) => $this->bills->present($b, true, $costs))
            ->values();

        return response()->json([
            'period' => $period->key(),
            'currency' => BillingContext::currency(),
            'total' => round((float) $bills->sum('total'), 2),
            'data' => $bills,
        ]);
    }
}
