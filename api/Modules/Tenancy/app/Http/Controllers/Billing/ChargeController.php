<?php

namespace Modules\Tenancy\Http\Controllers\Billing;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Http\Controllers\Billing\Concerns\ResolvesBillingTenant;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Services\Billing\ChargeService;

/**
 * Billing API — charges, what each tenant owes per period (docs/billing.md). Platform
 * administrators and API tokens with billing.view (reads) / billing.manage (writes).
 *
 *  GET   /api/billing/charges                  ?tenant= &period=YYYY-MM &status= &kind= &closed_since= &external_ref=none &per_page=
 *  GET   /api/billing/charges/{charge}         one charge; an open one with its line items and total as of now
 *  PATCH /api/billing/charges/{charge}         {external_ref} — the billing system's reference (its invoice)
 *  POST  /api/billing/charges/{charge}/void    {reason?} — not owed
 *  POST  /api/billing/tenants/{tenant}/charges {description, amount, currency?, period_start?, period_end?} — one-off charge
 */
class ChargeController extends Controller
{
    use ResolvesBillingTenant;

    public function __construct(private readonly ChargeService $charges) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tenant' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(Charge::STATUSES)],
            'kind' => ['sometimes', Rule::in(Charge::KINDS)],
            'closed_since' => ['sometimes', 'date'],
            'external_ref' => ['sometimes', 'string', 'max:191'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Charge::query();

        if ($tenant = $request->query('tenant')) {
            $query->where('tenant_id', (string) $this->billingTenant((string) $tenant)->id);
        }
        if ($range = $this->periodRange($request)) {
            $query->whereDate('period_start', '>=', $range[0])->whereDate('period_start', '<=', $range[1]);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($since = $request->query('closed_since')) {
            $query->where('closed_at', '>=', Carbon::parse((string) $since));
        }
        if (($ref = $request->query('external_ref')) !== null) {
            $ref === 'none' ? $query->whereNull('external_ref') : $query->where('external_ref', $ref);
        }

        $page = $query->orderByDesc('period_start')->orderBy('tenant_id')->orderBy('id')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Charge $c) => $c->toApiArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $charge): JsonResponse
    {
        return response()->json(['charge' => $this->charges->present(Charge::query()->findOrFail($charge))]);
    }

    public function update(Request $request, string $charge): JsonResponse
    {
        $model = Charge::query()->findOrFail($charge);
        $data = $request->validate(['external_ref' => ['present', 'nullable', 'string', 'max:191']]);

        $model->forceFill(['external_ref' => $data['external_ref']])->save();

        return response()->json(['charge' => $this->charges->present($model)]);
    }

    public function void(Request $request, string $charge): JsonResponse
    {
        $model = Charge::query()->findOrFail($charge);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return response()->json(['charge' => $this->charges->void($model, $data['reason'] ?? null)->toApiArray()]);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        $model = $this->billingTenant($tenant);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'period_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_end' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        $charge = $this->charges->createManual($model, $data);

        return response()->json(['charge' => $this->charges->present($charge)], 201);
    }
}
