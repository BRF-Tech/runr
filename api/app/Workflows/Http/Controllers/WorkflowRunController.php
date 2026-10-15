<?php

namespace App\Workflows\Http\Controllers;

use App\Models\Project;
use App\Workflows\Engine\RunControl;
use App\Workflows\Engine\RunStarter;
use App\Workflows\Engine\WorkflowException;
use App\Workflows\Http\Resources\WorkflowRunResource;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Runs of a project's workflows: list / detail, start, cancel, retry, replay.
 */
class WorkflowRunController
{
    public function __construct(
        private readonly RunStarter $starter,
        private readonly RunControl $control,
    ) {}

    /**
     * GET /projects/{project}/workflow-runs and GET /projects/{project}/workflows/{workflow}/runs
     * Filters: status (comma separated), workflow (name), source, q (run id / event name),
     * from, to (ISO dates), per_page (≤ 100).
     */
    public function index(Request $request, Project $project, ?string $workflow = null): AnonymousResourceCollection
    {
        Gate::authorize('workflows.view', $project);

        $filters = $request->validate([
            'status' => 'nullable|string|max:200',
            'workflow' => 'nullable|string|max:100',
            'source' => ['nullable', 'string', Rule::in(WorkflowRun::SOURCES)],
            'q' => 'nullable|string|max:200',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = WorkflowRun::with('workflow:id,name')->where('project_id', $project->id);

        $name = $workflow ?? ($filters['workflow'] ?? null);
        if ($name !== null) {
            $query->whereHas('workflow', fn ($q) => $q->where('name', $name));
        }

        if (! empty($filters['status'])) {
            $statuses = array_values(array_intersect(explode(',', $filters['status']), WorkflowRun::STATUSES));
            $query->whereIn('status', $statuses ?: ['__none__']);
        }
        if (! empty($filters['source'])) {
            $query->where('trigger_source', $filters['source']);
        }
        if (! empty($filters['q'])) {
            $q = $filters['q'];
            // json columns are cast to text for LIKE (PostgreSQL has no LIKE on json)
            $query->where(fn ($w) => $w->where('id', $q)
                ->orWhere('event_id', $q)
                ->orWhere('idempotency_key', $q)
                ->orWhereRaw('CAST(event AS TEXT) LIKE ?', ['%'.addcslashes($q, '%_\\').'%']));
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $runs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));

        return WorkflowRunResource::collection($runs);
    }

    /** GET /projects/{project}/workflow-runs/{run} */
    public function show(Project $project, string $run): JsonResponse
    {
        Gate::authorize('workflows.view', $project);

        $model = $this->find($project, $run)->load(['workflow:id,name', 'steps', 'children:id,parent_run_id,status']);

        return response()->json(['data' => new WorkflowRunResource($model)]);
    }

    /** POST /projects/{project}/workflows/{workflow}/runs  {data?, idempotency_key?, test?} */
    public function start(Request $request, Project $project, string $workflow): JsonResponse
    {
        Gate::authorize('workflows.run', $project);

        $data = $request->validate([
            'data' => 'nullable|array',
            'idempotency_key' => 'nullable|string|max:200',
            'test' => 'nullable|boolean',
        ]);

        $model = Workflow::where('project_id', $project->id)->where('name', $workflow)->first();
        abort_if(! $model, 404, __('workflows.not_found'));

        $result = $this->starter->start($model, [
            'name' => 'runr/api.start',
            'data' => $this->objectData($request),
        ], [
            'source' => ($data['test'] ?? false) ? 'test' : 'api',
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'started_by' => (string) $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json([
            'data' => new WorkflowRunResource($result->run->load('workflow:id,name')),
            'created' => $result->created,
        ], $result->created ? 201 : 200);
    }

    /** POST /projects/{project}/workflow-runs/{run}/cancel */
    public function cancel(Request $request, Project $project, string $run): JsonResponse
    {
        Gate::authorize('workflows.run', $project);

        $model = $this->find($project, $run);
        $user = $request->user();
        $label = $user->name ?? $user->email ?? (string) $user?->getAuthIdentifier();

        if (! $this->control->cancel($model, __('workflows.cancelled_by', ['user' => $label]))) {
            throw WorkflowException::conflict(__('workflows.run_not_active'), 'run_not_active');
        }

        return response()->json(['data' => new WorkflowRunResource($model->fresh()->load('workflow:id,name'))]);
    }

    /** POST /projects/{project}/workflow-runs/{run}/retry — the same run, failed steps again */
    public function retry(Project $project, string $run): JsonResponse
    {
        Gate::authorize('workflows.run', $project);

        $model = $this->control->retry($this->find($project, $run));

        return response()->json(['data' => new WorkflowRunResource($model->load('workflow:id,name'))]);
    }

    /** POST /projects/{project}/workflow-runs/{run}/replay {from_step?} — a new run */
    public function replay(Request $request, Project $project, string $run): JsonResponse
    {
        Gate::authorize('workflows.run', $project);

        $data = $request->validate(['from_step' => 'nullable|string|max:255']);

        $replay = $this->control->replay(
            $this->find($project, $run),
            $data['from_step'] ?? null,
            (string) $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['data' => new WorkflowRunResource($replay->load('workflow:id,name'))], 201);
    }

    private function find(Project $project, string $id): WorkflowRun
    {
        $run = WorkflowRun::where('project_id', $project->id)->whereKey($id)->first();
        abort_if(! $run, 404, __('workflows.run_not_found'));

        return $run;
    }

    /**
     * `data` of the request body as a JSON object, decoded from the raw body so nested `{}`
     * stay objects (missing / null: {}).
     *
     * @throws WorkflowException
     */
    private function objectData(Request $request): object
    {
        $raw = json_decode($request->getContent() ?: 'null', false);
        $data = is_object($raw) ? ($raw->data ?? null) : $request->input('data');

        if ($data === null || $data === []) {
            return new \stdClass;
        }
        if (is_array($data) && ! array_is_list($data)) {
            return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false);
        }
        if (! is_object($data)) {
            throw WorkflowException::invalid(__('workflows.event_data_object', ['index' => 0]), 'event_data_invalid');
        }

        return $data;
    }
}
