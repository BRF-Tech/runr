<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\Bill;
use Modules\Billing\Services\BillService;
use Modules\Billing\Support\Period;

/**
 * Bills of the organization's projects and members (docs/billing.md#api):
 *
 *  GET /api/billing/bills                     ?period=YYYY-MM &status=open|closed &paid=true|false
 *                                             &owner=<user id> &project=<project id> &kind=project|account &per_page=
 *  GET /api/billing/bills/{bill}              one bill with its line items (an open one as of now)
 *  PUT /api/billing/bills/{bill}/payment-url  {payment_url: url|null} — closed bills
 *  PUT /api/billing/bills/{bill}/paid         {paid, paid_at?, payment_reference?} — closed bills
 *
 * RunR never restricts anything based on the paid status.
 */
class BillController extends Controller
{
    use BillingRequests;

    public function __construct(private readonly BillService $bills) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['sometimes', Rule::in([Bill::OPEN, Bill::CLOSED])],
            'paid' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'owner' => ['sometimes', 'string', 'max:64'],
            'project' => ['sometimes', 'string', 'max:26'],
            'kind' => ['sometimes', Rule::in([Bill::PROJECT, Bill::ACCOUNT])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        // The month's open bills exist for every project before they are listed
        if (! $request->filled('period') || Period::fromKey((string) $request->query('period'))->isCurrent()) {
            $this->bills->ensureOpenBills();
        }

        $query = $this->bills->query()
            ->when($request->filled('period'), fn ($q) => $q->where('period', (string) $request->query('period')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($this->boolQuery($request, 'paid') !== null, fn ($q) => $q->where('paid', $this->boolQuery($request, 'paid')))
            ->when($request->filled('owner'), fn ($q) => $q->where('owner_id', (string) $request->query('owner')))
            ->when($request->filled('project'), fn ($q) => $q->where('project_id', (string) $request->query('project')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', (string) $request->query('kind')))
            ->orderByDesc('period')->orderBy('kind')->orderBy('project_name')->orderBy('id');

        $page = $query->paginate($this->perPage($request));
        $costs = $this->canViewCosts($request);

        return $this->paginated($page, collect($page->items())->map(fn (Bill $b) => $this->bills->present($b, false, $costs))->all());
    }

    public function show(Request $request, string $bill): JsonResponse
    {
        return response()->json(['data' => $this->bills->present($this->bills->find($bill), true, $this->canViewCosts($request))]);
    }

    public function paymentUrl(Request $request, string $bill): JsonResponse
    {
        $data = $request->validate(['payment_url' => ['present', 'nullable', 'string', 'max:2000', 'url:http,https']]);

        $model = $this->bills->setPaymentUrl($this->bills->find($bill), $data['payment_url'] ?? null);

        return response()->json(['data' => $this->bills->present($model, true, true)]);
    }

    public function paid(Request $request, string $bill): JsonResponse
    {
        $data = $request->validate([
            'paid' => ['required', 'boolean'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $model = $this->bills->setPaid(
            $this->bills->find($bill),
            (bool) $data['paid'],
            $data['paid_at'] ?? null,
            $data['payment_reference'] ?? null,
            array_key_exists('payment_reference', $data),
        );

        return response()->json(['data' => $this->bills->present($model, true, true)]);
    }
}
