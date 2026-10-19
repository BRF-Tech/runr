<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Services\BillService;
use Modules\Billing\Support\BillingContext;

/**
 * Bills rolled up per payer (docs/billing.md#per-user-rollup):
 *
 *  GET /api/billing/rollup   ?period=YYYY-MM — per owner: projects, bills, total, paid and unpaid totals
 */
class RollupController extends Controller
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

        return response()->json([
            'period' => $period->key(),
            'currency' => BillingContext::currency(),
            'data' => $this->bills->rollup($period),
        ]);
    }
}
