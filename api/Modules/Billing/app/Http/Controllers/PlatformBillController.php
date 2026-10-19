<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\Bill;
use Modules\Billing\Services\TenantBillService;
use Modules\Billing\Support\Period;

/**
 * The tenant's bills from the platform (docs/billing.md#platform-to-tenant-bills), read-only:
 *
 *  GET /api/billing/platform-bills          ?period= &status= &paid=
 *  GET /api/billing/platform-bills/{bill}   with line items
 *
 * Tenant context only; the payment link and the paid status come from the platform's invoicing system.
 */
class PlatformBillController extends Controller
{
    use BillingRequests;

    public function __construct(private readonly TenantBillService $bills) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $this->tenantOrFail()->getKey();
        $request->validate([
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['sometimes', Rule::in([Bill::OPEN, Bill::CLOSED])],
            'paid' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        if (! $request->filled('period') || Period::fromKey((string) $request->query('period'))->isCurrent()) {
            $this->bills->tenantBill($tenantId, Period::current());
        }

        $page = $this->bills->query()->where('tenant_id', $tenantId)
            ->when($request->filled('period'), fn ($q) => $q->where('period', (string) $request->query('period')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($this->boolQuery($request, 'paid') !== null, fn ($q) => $q->where('paid', $this->boolQuery($request, 'paid')))
            ->orderByDesc('period')->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginated($page, collect($page->items())->map(fn (Bill $b) => $this->bills->present($b, false, false))->all());
    }

    public function show(string $bill): JsonResponse
    {
        $tenantId = (string) $this->tenantOrFail()->getKey();

        return response()->json(['data' => $this->bills->present($this->bills->find($bill, $tenantId), true, false)]);
    }
}
