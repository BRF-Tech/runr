<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectStopConflict;
use App\Support\Rbac\Rbac;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\CostLimit;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Services\BillService;
use Modules\Billing\Services\CostLimits;
use Modules\Billing\Services\FixedRequests;
use Modules\Billing\Services\Presenter;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Support\BillingContext;

/**
 * A project's billing tab (docs/billing.md#the-project-billing-tab):
 *
 *  GET    /api/projects/{project}/billing                                   type, allocation, pending request,
 *                                                                           this month's estimate, past bills,
 *                                                                           the monthly cost limit
 *  GET    /api/projects/{project}/billing/quote                             ?cpu_m= &memory_mb= &gpu_count=
 *  GET    /api/projects/{project}/billing/bills/{bill}                      one bill of the project
 *  POST   /api/projects/{project}/billing/fixed-requests                    {cpu_m, memory_mb, gpu_count?, note?}
 *  POST   /api/projects/{project}/billing/fixed-requests/{fixedRequest}/cancel
 *  GET    /api/projects/{project}/billing/cost-limit                        the limit, month to date, forecast
 *  PUT    /api/projects/{project}/billing/cost-limit                        {amount, action: notify|stop, currency?}
 *  DELETE /api/projects/{project}/billing/cost-limit
 *  POST   /api/projects/{project}/billing/cost-limit/resume                 a project its limit stopped
 *
 * Besides seeing the project (route rule projects.view) the caller is the project's owner (the
 * payer), holds projects.billing on the project or billing.view in the organization; requests,
 * the cost limit and its resume need the owner, projects.billing or billing.manage.
 */
class ProjectBillingController extends Controller
{
    /**
     * The API document's shape of a project's cost limit (CostLimits::status()): available false
     * for a project the limit does not apply to (fixed price, free region), without figures
     */
    private const COST_LIMIT_TYPE = 'array{available: bool, currency: string, period: string, limit: array{amount: float, action: "notify"|"stop", updated_by: array{id: string, name: string}|null, updated_at: string|null}|null, month_to_date: float|null, forecast: float|null, days_elapsed: int, days_in_month: int, percent: float|null, warning_percent: int, state: "none"|"ok"|"warning"|"reached", warned_at: string|null, reached_at: string|null, stopped: bool, can_manage: bool, can_resume: bool, checked_at: string|null}';

    use BillingRequests;

    /** Past bills on the tab */
    private const HISTORY = 24;

    public function __construct(
        private readonly BillService $bills,
        private readonly FixedRequests $requests,
        private readonly Presenter $presenter,
        private readonly Regions $regions,
        private readonly RegionTypes $types,
        private readonly CostLimits $limits,
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
            // Pay-as-you-go projects only (available false otherwise: the tab shows nothing of it)
            'cost_limit' => $this->limits->status($project, $this->canRequest($request, $project)),
        ]);
    }

    #[OpenApiResponse(200, 'The limit and this month so far', type: 'array{data: '.self::COST_LIMIT_TYPE.'}')]
    public function costLimit(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        return response()->json(['data' => $this->limits->status($project, $this->canRequest($request, $project))]);
    }

    /** Set or change the limit; checked right away (a limit the month already passed acts now). */
    #[OpenApiResponse(200, 'The limit and this month so far', type: 'array{data: '.self::COST_LIMIT_TYPE.'}')]
    public function setCostLimit(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        if ($request->has('currency') && is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper(trim($request->input('currency')))]);
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:1000000000'],
            'action' => ['required', 'string', Rule::in(CostLimit::ACTIONS)],
            // The billing currency (one per installation): given, it has to be that one
            'currency' => ['sometimes', 'nullable', 'string', Rule::in([BillingContext::currency()])],
        ], [
            'currency.in' => __('billing.cost_limit.currency', ['currency' => BillingContext::currency()]),
        ]);

        $limit = $this->limits->set($project, (string) $data['amount'], $data['action'], (string) $request->user()->getAuthIdentifier());

        return response()->json(['data' => $this->limits->status($project->refresh(), true, $limit)]);
    }

    /** Remove the limit; a project it stopped stays stopped until it is resumed. */
    #[OpenApiResponse(200, 'No limit any more and this month so far', type: 'array{data: '.self::COST_LIMIT_TYPE.'}')]
    public function clearCostLimit(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        $this->limits->clear($project);

        return response()->json(['data' => $this->limits->status($project, true)]);
    }

    /**
     * Resume a project its cost limit stopped — once the limit no longer stops it (raised,
     * removed, switched to notify, or a new month). Answers like POST /projects/{project}/resume.
     */
    #[OpenApiResponse(200, 'The project runs again: the limit, the project, the deployment it was redeployed with (or why the redeploy was refused)', type: 'array{data: '.self::COST_LIMIT_TYPE.', project: ProjectResource, deployment_id: string|null, redeploy_error: string|null, redeploy_code: string|null}')]
    #[OpenApiResponse(409, 'The limit did not stop the project, still stops it, or the project is not stopped', type: 'array{message: string, code: "cost_limit_not_stopped"|"cost_limit_still_reached"|"project_not_stopped"}')]
    #[OpenApiResponse(403, 'The platform stopped the project: only a platform administrator resumes it', type: 'array{message: string, code: "project_stopped_by_platform"}')]
    public function resumeCostLimit(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);

        try {
            $deployment = $this->limits->resume($project, $request->user(), $refusal, $refusalCode);
        } catch (ProjectStopConflict $e) {
            return $e->render();
        }

        $project->refresh()->load(['knativeRegion', 'latestVersion', 'latestDeployment.version', 'creator:id,name', 'owner:id,name,username']);

        return response()->json([
            'data' => $this->limits->status($project, true),
            'project' => (new ProjectResource($project))->resolve($request),
            'deployment_id' => $deployment?->id,
            'redeploy_error' => $refusal,
            'redeploy_code' => $refusalCode,
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
