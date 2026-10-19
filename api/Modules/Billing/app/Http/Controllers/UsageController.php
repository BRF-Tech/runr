<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Services\Presenter;
use Modules\Billing\Services\UsageReader;
use Modules\Billing\Support\BillingContext;

/**
 * Metered usage per project and month (docs/billing.md#usage):
 *
 *  GET /api/billing/usage   ?period=YYYY-MM (default: this month so far) &project=<id>
 *
 * Quantities (vCPU-hours, GB-hours, GPU-hours, sessions) and their pay-as-you-go cost at the
 * prices of each day — also for fixed projects, whose bill is the fixed price.
 */
class UsageController extends Controller
{
    use BillingRequests;

    public function __construct(
        private readonly UsageReader $usage,
        private readonly BillCalculator $calculator,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'project' => ['sometimes', 'string', 'max:26']]);
        $period = $this->period($request);

        $byProject = $this->usage->projectsUsage($period);
        if ($request->filled('project')) {
            $id = (string) $request->query('project');
            $byProject = [$id => $byProject[$id] ?? []];
        }

        $projects = Project::query()->whereIn('id', array_keys($byProject))->get()->keyBy('id');
        $data = [];
        foreach ($byProject as $projectId => $entries) {
            $project = $projects->get($projectId);
            $owner = $project?->payerId();
            $data[] = [
                'project' => ['id' => (string) $projectId, 'name' => $project?->name],
                'owner' => $owner !== null ? ($this->presenter->user($owner) ?? ['id' => $owner, 'name' => null, 'email' => null]) : null,
                'region_ids' => array_values(array_unique(array_filter(array_column($entries, 'region_id')))),
                'quantities' => [
                    'vcpu_hours' => round(array_sum(array_column($entries, 'vcpu_hours')), 4),
                    'memory_gb_hours' => round(array_sum(array_column($entries, 'memory_gb_hours')), 4),
                    'gpu_hours' => round(array_sum(array_column($entries, 'gpu_hours')), 4),
                    'sessions' => (int) array_sum(array_column($entries, 'sessions')),
                ],
                'cost' => $this->calculator->meteredCost($entries),
            ];
        }

        return response()->json([
            'period' => $period->key(),
            'from' => $period->startDate(),
            'to' => $period->endDate(),
            'currency' => BillingContext::currency(),
            'data' => $data,
        ]);
    }
}
