<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Services\FixedRequests;
use Modules\Billing\Services\Presenter;

/**
 * Fixed resource requests of the organization's projects (docs/billing.md#fixed-regions):
 *
 *  GET  /api/billing/fixed-requests                         ?status= &project= &region= &per_page=
 *  POST /api/billing/fixed-requests/{fixedRequest}/approve  {note?} — the allocation from today
 *  POST /api/billing/fixed-requests/{fixedRequest}/reject   {reason}
 *
 * Approving is the seller's decision (billing.approve): the install's administrators, a tenant's
 * administrators for its users, the platform's administrators for the platform's own projects.
 */
class FixedRequestController extends Controller
{
    use BillingRequests;

    public function __construct(
        private readonly FixedRequests $requests,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', Rule::in(FixedRequest::STATUSES)],
            'project' => ['sometimes', 'string', 'max:26'],
            'region' => ['sometimes', 'string', 'max:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $page = FixedRequest::query()->own()
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('project'), fn ($q) => $q->where('project_id', (string) $request->query('project')))
            ->when($request->filled('region'), fn ($q) => $q->where('region_id', (string) $request->query('region')))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($page, collect($page->items())->map(fn (FixedRequest $r) => $this->presenter->fixedRequest($r))->all());
    }

    public function approve(Request $request, string $fixedRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        $model = $this->requests->approve($this->find($fixedRequest), $request->user(), $data['note'] ?? null);

        return response()->json(['data' => $this->presenter->fixedRequest($model)]);
    }

    public function reject(Request $request, string $fixedRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        $model = $this->requests->reject($this->find($fixedRequest), $request->user(), $data['reason']);

        return response()->json(['data' => $this->presenter->fixedRequest($model)]);
    }

    private function find(string $id): FixedRequest
    {
        return FixedRequest::query()->own()->whereKey($id)->firstOrFail();
    }
}
