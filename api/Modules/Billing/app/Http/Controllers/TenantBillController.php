<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\Bill;
use Modules\Billing\Services\TenantBillService;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Period;

/**
 * Platform → tenant bills (docs/billing.md#platform-to-tenant-bills), platform administrators:
 *
 *  GET /api/billing/tenant-bills                     ?tenant=<id|slug> &period= &status= &paid= &per_page=
 *  GET /api/billing/tenant-bills/{bill}              with line items per project and region
 *  PUT /api/billing/tenant-bills/{bill}/payment-url  {payment_url|null} — closed bills
 *  PUT /api/billing/tenant-bills/{bill}/paid         {paid, paid_at?, payment_reference?} — closed bills
 */
class TenantBillController extends Controller
{
    use BillingRequests;

    public function __construct(private readonly TenantBillService $bills) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'tenant' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in([Bill::OPEN, Bill::CLOSED])],
            'paid' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $tenantId = null;
        if ($request->filled('tenant')) {
            $tenantId = $this->bills->resolveTenant((string) $request->query('tenant'));
            if ($tenantId === null) {
                abort(404, __('billing.tenant_not_found'));
            }
        }

        if (BillingContext::multiTenant() && (! $request->filled('period') || Period::fromKey((string) $request->query('period'))->isCurrent())) {
            $this->bills->ensureOpenBills();
        }

        $page = $this->bills->query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($request->filled('period'), fn ($q) => $q->where('period', (string) $request->query('period')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($this->boolQuery($request, 'paid') !== null, fn ($q) => $q->where('paid', $this->boolQuery($request, 'paid')))
            ->orderByDesc('period')->orderBy('tenant_id')->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginated($page, collect($page->items())->map(fn (Bill $b) => $this->bills->present($b, false, true))->all());
    }

    public function show(string $bill): JsonResponse
    {
        return response()->json(['data' => $this->bills->present($this->bills->find($bill), true, true)]);
    }

    public function paymentUrl(Request $request, string $bill): JsonResponse
    {
        $data = $request->validate(['payment_url' => ['present', 'nullable', 'string', 'max:2000', 'url:http,https']]);

        return response()->json(['data' => $this->bills->present($this->bills->setPaymentUrl($this->bills->find($bill), $data['payment_url'] ?? null), true, true)]);
    }

    public function paid(Request $request, string $bill): JsonResponse
    {
        $data = $request->validate([
            'paid' => ['required', 'boolean'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $model = $this->bills->setPaid($this->bills->find($bill), (bool) $data['paid'], $data['paid_at'] ?? null, $data['payment_reference'] ?? null, array_key_exists('payment_reference', $data));

        return response()->json(['data' => $this->bills->present($model, true, true)]);
    }
}
