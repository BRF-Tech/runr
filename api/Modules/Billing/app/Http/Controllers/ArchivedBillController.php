<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\ArchivedBill;
use Modules\Billing\Models\Bill;
use Modules\Billing\Services\BillArchive;

/**
 * Bills of deleted tenants (docs/billing.md#bills-of-deleted-tenants), platform administrators,
 * read-only: the project and account bills a tenant had made when it was deleted.
 *
 *  GET /api/billing/archived-bills          ?tenant=<id|slug> &period= &status= &paid= &kind= &per_page=
 *  GET /api/billing/archived-bills/{bill}   with line items and metered lines
 */
class ArchivedBillController extends Controller
{
    use BillingRequests;

    public function __construct(private readonly BillArchive $archive) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tenant' => ['sometimes', 'string', 'max:64'],
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['sometimes', Rule::in([Bill::OPEN, Bill::CLOSED])],
            'paid' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'kind' => ['sometimes', Rule::in([Bill::PROJECT, Bill::ACCOUNT])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        if (! $this->archive->tableExists()) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $this->perPage($request), 'total' => 0]]);
        }

        // The tenant is gone: its id or the slug it had
        $tenant = $request->filled('tenant') ? (string) $request->query('tenant') : null;

        $page = $this->archive->query()
            ->when($tenant !== null, fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', $tenant)->orWhere('tenant_slug', $tenant)))
            ->when($request->filled('period'), fn ($q) => $q->where('period', (string) $request->query('period')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', (string) $request->query('kind')))
            ->when($this->boolQuery($request, 'paid') !== null, fn ($q) => $q->where('paid', $this->boolQuery($request, 'paid')))
            ->orderByDesc('period')->orderBy('tenant_id')->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginated($page, collect($page->items())->map(fn (ArchivedBill $b) => $this->archive->present($b))->all());
    }

    public function show(string $bill): JsonResponse
    {
        abort_unless($this->archive->tableExists(), 404);

        return response()->json(['data' => $this->archive->present($this->archive->query()->whereKey($bill)->firstOrFail(), true)]);
    }
}
