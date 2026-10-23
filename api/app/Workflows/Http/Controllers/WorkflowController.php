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
use App\Workflows\Support\WorkflowEnvironments;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Workflow definitions and the workflow app (signing key, endpoint, sync) of a project in one
 * environment: `environment` (a name or id, in the query or the body; none or `production`: the
 * project's own workload) — docs/workflows.md#environments.
 */
class WorkflowController
{
    public function __construct(
        private readonly WorkflowRegistry $registry,
    ) {}

    /** GET /projects/{project}/workflows */
    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.view', $project);
        $request->validate(['environment' => 'nullable|string|max:64']);

        return response()->json($this->listing(WorkflowEnvironments::fromRequest($request, $project)));
    }

    /** GET /projects/{project}/workflows/{workflow} */
    public function show(Request $request, Project $project, string $workflow): JsonResponse
    {
        Gate::authorize('workflows.view', $project);
        $request->validate(['environment' => 'nullable|string|max:64']);

        $model = $this->find(WorkflowEnvironments::fromRequest($request, $project), $workflow);
        $this->attachStats(collect([$model]));

        return response()->json(['data' => new WorkflowResource($model)]);
    }

    /** POST /projects/{project}/workflows/sync — introspect the deployed function of the environment */
    public function sync(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);
        $request->validate(['environment' => 'nullable|string|max:64']);

        $view = WorkflowEnvironments::fromRequest($request, $project);
        $result = $this->registry->sync($view);

        return response()->json(['message' => __('workflows.synced'), 'source' => $result['source']] + $this->listing($view));
    }

    /**
     * PUT /projects/{project}/workflows — declare the set of definitions (manifest): the ones it
     * does not list are archived. A definition declared unchanged keeps its source (e.g. `sdk`).
     */
    public function declare(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $data = $request->validate([
            'workflows' => 'present|array',
            'environment' => 'nullable|string|max:64',
        ]);

        $view = WorkflowEnvironments::fromRequest($request, $project);
        $this->registry->declare($view, $data['workflows'], 'api');

        return response()->json(['message' => __('workflows.declared')] + $this->listing($view));
    }

    /**
     * PUT /projects/{project}/workflows/{workflow} — declare one definition (the body: its fields,
     * as in runr.workflows.json, without or with the same name): added or replaced, the others stay
     * as they are. Declared unchanged, it keeps its source.
     */
    #[BodyParameter('environment', 'The environment (a name or id; left out or production: the project\'s own workload)', type: 'string|null')]
    #[BodyParameter('name', 'The workflow\'s name, if given: the one in the address', type: 'string')]
    #[BodyParameter('description', type: 'string|null')]
    #[BodyParameter('triggers', 'What starts a run: an event (with an optional if), a cron schedule (with an optional timezone) or a queue stream', type: 'list<array{event?: string, if?: string, cron?: string, timezone?: string, queue?: string}>|null')]
    #[BodyParameter('retries', 'Retries of a failed step (default 3), or {attempts: n}', type: 'int|array{attempts: int}|null')]
    #[BodyParameter('timeout', 'Longest a run may take, e.g. "1d" (seconds as a number)', type: 'string|int|null')]
    #[BodyParameter('concurrency', 'A limit, a limit with a key expression, or a list of at most two (one keyed)', type: 'int|array{limit: int, key?: string}|list<array{limit: int, key?: string}>|null')]
    #[BodyParameter('idempotency', 'Expression of the idempotency key', type: 'string|null')]
    #[BodyParameter('cancel_on', 'Events that cancel matching active runs', type: 'list<array{event: string, match?: string, if?: string}>|null')]
    public function declareOne(Request $request, Project $project, string $workflow): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        // the definition itself is checked as runr.workflows.json's (Definition::fromArray);
        // in a variable: the document's generator takes the fields from #[BodyParameter]
        $rules = [
            'environment' => 'nullable|string|max:64',
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'triggers' => 'nullable|array',
            'cancel_on' => 'nullable|array',
        ];
        $request->validate($rules);

        $view = WorkflowEnvironments::fromRequest($request, $project);
        $model = $this->registry->declareOne($view, $workflow, $request->except('environment'));
        $this->attachStats(collect([$model]));

        return response()->json(['message' => __('workflows.declared_one', ['name' => $model->name]), 'data' => new WorkflowResource($model)]);
    }

    /**
     * DELETE /projects/{project}/workflows/{workflow} — archive one definition: its runs stay, it
     * starts no new runs; the others stay as they are.
     */
    public function archive(Request $request, Project $project, string $workflow): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);
        $request->validate(['environment' => 'nullable|string|max:64']);

        $model = $this->registry->archive(WorkflowEnvironments::fromRequest($request, $project), $workflow);
        $this->attachStats(collect([$model]));

        return response()->json(['message' => __('workflows.archived', ['name' => $model->name]), 'data' => new WorkflowResource($model)]);
    }

    /** PATCH /projects/{project}/workflows/{workflow} — pause / resume */
    public function update(Request $request, Project $project, string $workflow): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $data = $request->validate(['is_active' => 'required|boolean', 'environment' => 'nullable|string|max:64']);
        $model = $this->find(WorkflowEnvironments::fromRequest($request, $project), $workflow);
        $model->forceFill(['is_active' => $data['is_active']])->save();

        return response()->json(['data' => new WorkflowResource($model->fresh())]);
    }

    /** GET /projects/{project}/workflows/app */
    public function app(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.view', $project);
        $request->validate(['environment' => 'nullable|string|max:64']);

        $view = WorkflowEnvironments::fromRequest($request, $project);

        return response()->json(['data' => new WorkflowAppResource(WorkflowApp::forProject($view), $view)]);
    }

    /** PATCH /projects/{project}/workflows/app — endpoint override (null: the environment's function URL) */
    public function updateApp(Request $request, Project $project, EndpointGuard $guard): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);

        $data = $request->validate(['endpoint_url' => 'present|nullable|string|max:2048', 'environment' => 'nullable|string|max:64']);
        $url = isset($data['endpoint_url']) && trim($data['endpoint_url']) !== '' ? trim($data['endpoint_url']) : null;

        if ($url !== null) {
            $guard->assertAllowed($url);
        }

        $view = WorkflowEnvironments::fromRequest($request, $project);
        $app = WorkflowApp::forProject($view);
        $app->forceFill(['endpoint_url' => $url])->save();

        return response()->json(['data' => new WorkflowAppResource($app, $view)]);
    }

    /** POST /projects/{project}/workflows/app/rotate-key */
    public function rotateKey(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);
        $request->validate(['environment' => 'nullable|string|max:64']);

        $view = WorkflowEnvironments::fromRequest($request, $project);
        $app = WorkflowApp::forProject($view);
        $app->rotateKey();

        return response()->json([
            'message' => __('workflows.key_rotated', ['until' => $app->previous_key_expires_at?->toDayDateTimeString()]),
            'data' => new WorkflowAppResource($app, $view),
        ]);
    }

    /** GET /projects/{project}/workflows/app/signing-key — for functions served outside RunR (endpoint override) */
    public function signingKey(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('workflows.manage', $project);
        $request->validate(['environment' => 'nullable|string|max:64']);

        $app = WorkflowApp::forProject(WorkflowEnvironments::fromRequest($request, $project));

        return response()->json([
            'signing_key' => $app->signing_key,
            'env' => 'RUNR_WORKFLOW_SIGNING_KEY',
            'fingerprint' => $app->keyFingerprint(),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @param  Project  $project  the project, or an environment's view */
    private function listing(Project $project): array
    {
        $workflows = Workflow::where('project_id', $project->id)
            ->inEnvironment(WorkflowEnvironments::idOf($project))
            ->orderByRaw('CASE WHEN archived_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get();

        $this->attachStats($workflows);

        return [
            'data' => WorkflowResource::collection($workflows),
            'app' => new WorkflowAppResource(WorkflowApp::forProject($project), $project),
        ];
    }

    private function attachStats($workflows): void
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

    /** @param  Project  $project  the project, or an environment's view */
    private function find(Project $project, string $name): Workflow
    {
        $workflow = Workflow::where('project_id', $project->id)
            ->inEnvironment(WorkflowEnvironments::idOf($project))
            ->where('name', $name)
            ->first();
        abort_if(! $workflow, 404, __('workflows.not_found'));

        return $workflow;
    }
}
