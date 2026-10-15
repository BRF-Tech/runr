<?php

namespace App\Workflows\Http\Controllers;

use App\Models\Project;
use App\Workflows\Engine\EndpointGuard;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Http\Resources\WorkflowAppResource;
use App\Workflows\Http\Resources\WorkflowResource;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Workflow definitions and the workflow app (signing key, endpoint, sync) of a project.
 */
class WorkflowController
{
    public function __construct(
        private readonly WorkflowRegistry $registry,
    ) {}

    /** GET /projects/{project}/workflows */
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('workflows.view', $project);

        return response()->json($this->listing($project));
    }

    /** GET /projects/{project}/workflows/{workflow} */
    public function show(Project $project, string $workflow): JsonResponse
    {
        Gate::authorize('workflows.view', $project);

        $model = $this->find($project, $workflow);
        $this->attachStats($project, collect([$model]));

        return response()->json(['data' => new WorkflowResource($model)]);
    }

    /** POST /projects/{project}/workflows/sync — introspect the deployed function */
    public function sync(Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $result = $this->registry->sync($project);

        return response()->json(['message' => __('workflows.synced'), 'source' => $result['source']] + $this->listing($project));
    }

    /** PUT /projects/{project}/workflows — declare definitions (manifest) */
    public function declare(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $data = $request->validate([
            'workflows' => 'present|array',
        ]);

        $this->registry->declare($project, $data['workflows'], 'api');

        return response()->json(['message' => __('workflows.declared')] + $this->listing($project));
    }

    /** PATCH /projects/{project}/workflows/{workflow} — pause / resume */
    public function update(Request $request, Project $project, string $workflow): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $data = $request->validate(['is_active' => 'required|boolean']);
        $model = $this->find($project, $workflow);
        $model->forceFill(['is_active' => $data['is_active']])->save();

        return response()->json(['data' => new WorkflowResource($model->fresh())]);
    }

    /** GET /projects/{project}/workflows/app */
    public function app(Project $project): JsonResponse
    {
        Gate::authorize('workflows.view', $project);

        return response()->json(['data' => new WorkflowAppResource(WorkflowApp::forProject($project), $project)]);
    }

    /** PATCH /projects/{project}/workflows/app — endpoint override (null: the project's function URL) */
    public function updateApp(Request $request, Project $project, EndpointGuard $guard): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $data = $request->validate(['endpoint_url' => 'present|nullable|string|max:2048']);
        $url = isset($data['endpoint_url']) && trim($data['endpoint_url']) !== '' ? trim($data['endpoint_url']) : null;

        if ($url !== null) {
            $guard->assertAllowed($url);
        }

        $app = WorkflowApp::forProject($project);
        $app->forceFill(['endpoint_url' => $url])->save();

        return response()->json(['data' => new WorkflowAppResource($app, $project)]);
    }

    /** POST /projects/{project}/workflows/app/rotate-key */
    public function rotateKey(Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $app = WorkflowApp::forProject($project);
        $app->rotateKey();

        return response()->json([
            'message' => __('workflows.key_rotated', ['until' => $app->previous_key_expires_at?->toDayDateTimeString()]),
            'data' => new WorkflowAppResource($app, $project),
        ]);
    }

    /** GET /projects/{project}/workflows/app/signing-key — for functions served outside RunR (endpoint override) */
    public function signingKey(Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $app = WorkflowApp::forProject($project);

        return response()->json([
            'signing_key' => $app->signing_key,
            'env' => 'RUNR_WORKFLOW_SIGNING_KEY',
            'fingerprint' => $app->keyFingerprint(),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function listing(Project $project): array
    {
        $workflows = Workflow::where('project_id', $project->id)
            ->orderByRaw('CASE WHEN archived_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get();

        $this->attachStats($project, $workflows);

        return [
            'data' => WorkflowResource::collection($workflows),
            'app' => new WorkflowAppResource(WorkflowApp::forProject($project), $project),
        ];
    }

    private function attachStats(Project $project, $workflows): void
    {
        $ids = $workflows->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        $counts = WorkflowRun::whereIn('workflow_id', $ids)
            ->selectRaw('workflow_id, status, count(*) as aggregate, max(created_at) as last_at')
            ->groupBy('workflow_id', 'status')
            ->get();

        foreach ($workflows as $workflow) {
            $rows = $counts->where('workflow_id', $workflow->id);
            $workflow->setAttribute('run_counts', $rows->mapWithKeys(fn ($r) => [$r->status => (int) $r->aggregate])->all() ?: new \stdClass);
            $workflow->setAttribute('last_run_at', $rows->max('last_at'));
        }
    }

    private function find(Project $project, string $name): Workflow
    {
        $workflow = Workflow::where('project_id', $project->id)->where('name', $name)->first();
        abort_if(! $workflow, 404, __('workflows.not_found'));

        return $workflow;
    }
}
