<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\Project;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Services\BillService;
use Modules\Billing\Services\FixedRequests;
use Modules\Billing\Services\Presenter;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Support\BillingContext;

/**
 * A project's billing tab (docs/billing.md#the-project-billing-tab):
 *
 *  GET  /api/projects/{project}/billing                                     type, allocation, pending request,
 *                                                                           this month's estimate, past bills
 *  GET  /api/projects/{project}/billing/quote                               ?cpu_m= &memory_mb= &gpu_count=
 *  GET  /api/projects/{project}/billing/bills/{bill}                        one bill of the project
 *  POST /api/projects/{project}/billing/fixed-requests                      {cpu_m, memory_mb, gpu_count?, note?}
 *  POST /api/projects/{project}/billing/fixed-requests/{fixedRequest}/cancel
 *
 * Besides seeing the project (route rule projects.view) the caller is the project's owner (the
 * payer), holds projects.billing on the project or billing.view in the organization.
 */
class ProjectBillingController extends Controller
{
    use BillingRequests;

    /** Past bills on the tab */
    private const HISTORY = 24;

    public function __construct(
        private readonly BillService $bills,
        private readonly FixedRequests $requests,
        private readonly Presenter $presenter,
        private readonly Regions $regions,
        private readonly RegionTypes $types,
        private readonly Rbac $rbac,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $costs = $this->canViewCosts($request);

        $current = $this->bills->projectBill($project);
        $past = $this->bills->query()->where('subject_key', Bill::projectKey((string) $project->id))
            ->where('status', Bill::CLOSED)->orderByDesc('period')->orderByDesc('period_start')->limit(self::HISTORY)->get();

        $allocation = $this->requests->active((string) $project->id, $project->knative_region_id);
        $pending = $this->requests->pending((string) $project->id);

        return response()->json([
            'currency' => BillingContext::currency(),
            'billing_type' => $this->types->typeOf($project->knative_region_id),
            'region' => $project->knative_region_id ? ['id' => $project->knative_region_id, 'name' => $this->regions->name($project->knative_region_id)] : null,
            'owner' => $this->presenter->user($project->payerId()),
            'can_request' => $this->canRequest($request, $project),
            'can_view_costs' => $costs,
            'meters_gpu' => $this->regions->metersGpu($project->knative_region_id),
            'allocation' => $allocation ? $this->presenter->fixedRequest($allocation) : null,
            'pending_request' => $pending ? $this->presenter->fixedRequest($pending) : null,
            'current' => $current ? $this->bills->present($current, true, $costs) : null,
            'bills' => $past->map(fn (Bill $b) => $this->bills->present($b, false, $costs))->values(),
        ]);
    }

    public function quote(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $data = $request->validate($this->resourceRules(false));

        return response()->json($this->requests->quote($project, (int) $data['cpu_m'], (int) $data['memory_mb'], (int) ($data['gpu_count'] ?? 0)));
    }

    public function bill(Request $request, Project $project, string $bill): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $model = $this->bills->query()->whereKey($bill)->where('subject_key', Bill::projectKey((string) $project->id))->firstOrFail();

        return response()->json(['data' => $this->bills->present($model, true, $this->canViewCosts($request))]);
    }

    public function storeRequest(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        $data = $request->validate($this->resourceRules(true));

        $model = $this->requests->create($project, $data, $request->user());

        return response()->json(['data' => $this->presenter->fixedRequest($model)], 201);
    }

    public function cancel(Request $request, Project $project, string $fixedRequest): JsonResponse
    {
        $this->authorizeProject($request, $project, true);

        $model = FixedRequest::query()->own()->whereKey($fixedRequest)->where('project_id', $project->id)->firstOrFail();

        return response()->json(['data' => $this->presenter->fixedRequest($this->requests->cancel($model))]);
    }

    /** @return array<string, mixed> */
    private function resourceRules(bool $withNote): array
    {
        return [
            'cpu_m' => ['required', 'integer', 'min:100', 'max:1000000'],
            'memory_mb' => ['required', 'integer', 'min:128', 'max:10000000'],
            'gpu_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:64'],
        ] + ($withNote ? ['note' => ['sometimes', 'nullable', 'string', 'max:1000']] : []);
    }

    private function isPayer(Request $request, Project $project): bool
    {
        $payer = $project->payerId();

        return $payer !== null && $payer === (string) $request->user()->getAuthIdentifier();
    }

    private function canRequest(Request $request, Project $project): bool
    {
        return $this->isPayer($request, $project)
            || $this->rbac->allows($request->user(), 'projects.billing', $project)
            || $this->rbac->allows($request->user(), 'billing.manage');
    }

    private function authorizeProject(Request $request, Project $project, bool $forRequest = false): void
    {
        $allowed = $forRequest
            ? $this->canRequest($request, $project)
            : ($this->isPayer($request, $project)
                || $this->rbac->allows($request->user(), 'projects.billing', $project)
                || $this->rbac->allows($request->user(), 'billing.view'));

        abort_unless($allowed, 403, __('billing.project_forbidden'));
    }
}
