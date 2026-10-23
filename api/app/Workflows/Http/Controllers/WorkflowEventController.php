<?php

namespace App\Workflows\Http\Controllers;

use App\Workflows\Engine\EventIngestor;
use App\Workflows\Engine\WorkflowException;
use App\Workflows\Http\Resources\WorkflowEventResource;
use App\Workflows\Http\Resources\WorkflowRunResource;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Support\WorkflowEnvironments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Events API of the current tenant (docs/workflows.md § Events API).
 *
 * POST /api/events accepts one event {"name", "data", "id"?, "ts"?, "environment"?}, a list of
 * events or {"events": [...], "environment"?}; `?test=1` marks them as test events (UI "send test
 * event"). `environment` (a name; `?environment=` too) is the environment the events belong to —
 * production when none (docs/workflows.md#environments); an event's own wins.
 */
class WorkflowEventController
{
    public function __construct(
        private readonly EventIngestor $ingestor,
    ) {}

    /** POST /events */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('events.send');

        // Decoded from the raw body so `{}` in event data stays an object
        $body = json_decode($request->getContent() ?: 'null', false);
        if ($body === null && $request->all() !== []) {
            $body = json_decode(json_encode($request->all(), JSON_THROW_ON_ERROR), false);
        }

        $events = match (true) {
            is_array($body) => $body,
            is_object($body) && isset($body->events) => is_array($body->events) ? $body->events : null,
            is_object($body) => [$body],
            default => null,
        };

        if (! is_array($events)) {
            throw WorkflowException::invalid(__('workflows.event_invalid', ['index' => 0]), 'event_invalid');
        }

        // The environment of every event of the request: ?environment=, or the envelope's
        $environment = WorkflowEnvironments::eventEnvironment(
            $request->query('environment') ?? (is_object($body) && isset($body->events) ? ($body->environment ?? null) : null),
        );

        $source = $request->boolean('test') ? 'test' : 'api';
        $results = $this->ingestor->ingest($events, $source, null, (string) $request->user()?->getAuthIdentifier(), $environment);

        return response()->json([
            'ids' => array_column($results, 'id'),
            'events' => $results,
        ]);
    }

    /** GET /events  Filters: name (prefix), source, project_id, environment (a name, production), from, to, per_page */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('workflows.view');

        $filters = $request->validate([
            'name' => 'nullable|string|max:200',
            'source' => ['nullable', 'string', Rule::in(WorkflowEvent::SOURCES)],
            'project_id' => 'nullable|string|max:26',
            'environment' => 'nullable|string|max:64',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = WorkflowEvent::query();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', addcslashes($filters['name'], '%_\\').'%');
        }
        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (! empty($filters['environment'])) {
            WorkflowEnvironments::constrainName($query, WorkflowEnvironments::eventEnvironment($filters['environment']));
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return WorkflowEventResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25))
        );
    }

    /** GET /events/{event} — the event and the runs it started */
    public function show(string $event): JsonResponse
    {
        Gate::authorize('workflows.view');

        $model = WorkflowEvent::find($event);
        abort_if(! $model, 404, __('workflows.event_not_found'));

        $runs = WorkflowRun::with('workflow:id,name')
            ->where('event_id', $model->id)
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => new WorkflowEventResource($model),
            'runs' => WorkflowRunResource::collection($runs),
        ]);
    }
}
