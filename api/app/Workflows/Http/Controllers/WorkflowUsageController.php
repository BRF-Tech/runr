<?php

namespace App\Workflows\Http\Controllers;

use App\Models\Project;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Workflow metering (docs/workflows.md § Usage): step executions and started runs per day,
 * for the current tenant or one project.
 */
class WorkflowUsageController
{
    /** GET /workflow-usage?from&to */
    public function tenant(Request $request): JsonResponse
    {
        Gate::authorize('workflows.view');

        return response()->json($this->report($request, null));
    }

    /** GET /projects/{project}/workflow-usage?from&to */
    public function project(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.view', $project);

        return response()->json($this->report($request, $project));
    }

    private function report(Request $request, ?Project $project): array
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = isset($data['from']) ? now()->parse($data['from'])->toDateString() : now()->subDays(29)->toDateString();
        $to = isset($data['to']) ? now()->parse($data['to'])->toDateString() : now()->toDateString();

        $query = WorkflowUsage::whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
        if ($project) {
            $query->where('project_id', $project->id);
        }

        $rows = $query->orderBy('date')->get();
        $names = Workflow::whereIn('id', $rows->pluck('workflow_id')->unique())->pluck('name', 'id');

        $daily = $rows->groupBy(fn ($r) => $r->date->toDateString())->map(fn ($day, $date) => [
            'date' => $date,
            'step_executions' => (int) $day->sum('step_executions'),
            'runs_started' => (int) $day->sum('runs_started'),
        ])->values();

        $byWorkflow = $rows->groupBy('workflow_id')->map(fn ($wf, $id) => [
            'workflow_id' => $id,
            'workflow' => $names[$id] ?? null,
            'project_id' => $wf->first()->project_id,
            'step_executions' => (int) $wf->sum('step_executions'),
            'runs_started' => (int) $wf->sum('runs_started'),
        ])->values();

        return [
            'from' => $from,
            'to' => $to,
            'totals' => [
                'step_executions' => (int) $rows->sum('step_executions'),
                'runs_started' => (int) $rows->sum('runs_started'),
            ],
            'daily' => $daily,
            'by_workflow' => $byWorkflow,
        ];
    }
}
