<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\Project;
use App\Models\ProjectEnvironment;
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
 * prices of each day — also for fixed projects, whose bill is the fixed price — and the same per
 * environment of the project (environments: production and each environment it ran in).
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
                'environments' => $this->byEnvironment($entries),
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

    /**
     * The project's usage per environment it came from (docs/environments.md): production (id
     * null) first, then each environment by name — previews are the project's own.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function byEnvironment(array $entries): array
    {
        $names = $this->calculator->environmentNames($entries);
        $groups = [];
        foreach ($entries as $entry) {
            $groups[(string) ($entry['environment_id'] ?? '')][] = $entry;
        }

        $out = [];
        foreach ($groups as $id => $group) {
            $out[] = [
                'id' => $id === '' ? null : $id,
                'name' => $id === '' ? ProjectEnvironment::PRIMARY_NAME : ($names[$id] ?? BillCalculator::DELETED_ENVIRONMENT),
                'quantities' => [
                    'vcpu_hours' => round(array_sum(array_column($group, 'vcpu_hours')), 4),
                    'memory_gb_hours' => round(array_sum(array_column($group, 'memory_gb_hours')), 4),
                    'gpu_hours' => round(array_sum(array_column($group, 'gpu_hours')), 4),
                    'sessions' => (int) array_sum(array_column($group, 'sessions')),
                ],
                'cost' => $this->calculator->meteredCost($group),
            ];
        }
        usort($out, fn (array $a, array $b) => [$a['id'] === null ? 0 : 1, $a['name']] <=> [$b['id'] === null ? 0 : 1, $b['name']]);

        return $out;
    }
}
