<?php

namespace App\Http\Controllers\Api\Triggers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\Cleanup\CdcSlotCleanup;
use App\Triggers\PlatformEvents;
use App\Triggers\TriggerPresenter;
use App\Triggers\TriggerService;
use App\Triggers\TriggerTypes;
use App\Triggers\Webhooks\WebhookVerifier;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A project's triggers (docs/triggers.md): list, add, change, remove, replace the whole list.
 * A change reaches the cluster with the project's next deploy (requires_deploy).
 *
 *   GET    /api/trigger-types
 *   GET    /api/projects/{project}/triggers
 *   POST   /api/projects/{project}/triggers                {type, name?, enabled?, position?, config?, secrets?}
 *   PUT    /api/projects/{project}/triggers                {triggers: [...]}
 *   PATCH  /api/projects/{project}/triggers/{trigger}      {name?, enabled?, position?, config?, secrets?}
 *   DELETE /api/projects/{project}/triggers/{trigger}
 *
 * {trigger} is the trigger's id or name.
 */
class ProjectTriggerController extends Controller
{
    /**
     * The API document's shape of a trigger (TriggerPresenter::one(), which adds the type's own
     * fields: a webhook's url and has_signing_secret, a schedule's effective time zone)
     */
    private const TRIGGER_TYPE = 'array{id: string|null, name: string, type: string, label: string, enabled: bool, position: int, primary: bool, runs_on: "service"|"gateway"|"cronjob"|"disabled"|"unknown", config: array<string, mixed>, secrets: list<string>, delivery: array{concurrency: int, max_retries: int, retry_backoff_seconds: int, retry_backoff_max_seconds: int}|null, retention_days: int|null, created_at: string|null, updated_at: string|null, url?: string|null, has_signing_secret?: bool, effective_timezone?: string, timezone_source?: "organization"|"trigger"}';

    /** The API document's shape of what a change left to clean up (CdcSlotCleanup::takeNotes()) */
    private const CLEANUP_TYPE = 'array{trigger: string, type: "cdc", slots: list<string>, publication: string|null, status: "scheduled", message: string}';

    /** The API document's shape of the plan of a project's triggers (TriggerPresenter::plan()) */
    private const PLAN_TYPE = 'array{workload: "cronjob"|"service", public: bool, gateway: bool, runtime_trigger: "schedule"|"workflow"|"http"|"queue", requires_redis: bool}';

    public function __construct(
        private TriggerService $service,
        private TriggerPresenter $presenter,
    ) {}

    #[OpenApiResponse(200, 'The trigger types with their config fields, the platform events and the webhook verification presets', type: 'array{data: list<array{type: string, label: string, description: string|null, serves_http: bool, gateway: bool, requires_redis: bool, exclusive: bool, stores_events: bool, secrets: list<string>, max_per_project: int|null, fields: list<array{key: string, type: "string"|"text"|"list"|"integer"|"boolean"|"select"|"duration"|"json", label: string, help: string|null, required: bool, default: mixed, options: list<string>|null, secret: bool}>}>, platform_events: list<string>, webhook_presets: list<string>}')]
    public function types(TriggerTypes $types): JsonResponse
    {
        return response()->json([
            'data' => $types->describe(),
            'platform_events' => PlatformEvents::ALL,
            'webhook_presets' => WebhookVerifier::PRESETS,
        ]);
    }

    #[OpenApiResponse(200, 'The project\'s triggers and their plan', type: 'array{data: list<'.self::TRIGGER_TYPE.'>, plan: '.self::PLAN_TYPE.'}')]
    public function index(Project $project): JsonResponse
    {
        return response()->json([
            'data' => $this->presenter->list($project),
            'plan' => $this->presenter->plan($project),
        ]);
    }

    /**
     * Adds a trigger: {type, name?, enabled?, position?, config?, secrets?} — config and secrets
     * as the type describes them (GET /api/trigger-types).
     */
    #[BodyParameter('type', 'The trigger type (GET /api/trigger-types)', required: true, type: 'string')]
    #[BodyParameter('name', 'Unique in the project; left out: the type, numbered when taken', type: 'string')]
    #[BodyParameter('enabled', 'Default true', type: 'bool')]
    #[BodyParameter('position', 'Place in the project\'s list (0–1000); left out: after the last', type: 'int')]
    #[BodyParameter('config', 'The type\'s settings (fields of GET /api/trigger-types)', type: 'array<string, mixed>')]
    #[BodyParameter('secrets', 'The type\'s write-only secrets (fields with secret: true)', type: 'array<string, string>')]
    #[OpenApiResponse(201, 'The new trigger and the plan of the project\'s triggers', type: 'array{data: '.self::TRIGGER_TYPE.', plan: '.self::PLAN_TYPE.', requires_deploy: bool}')]
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureSupported($project);
        $trigger = $this->service->create($project, $request->only(['type', 'name', 'enabled', 'position', 'config', 'secrets']), self::userId($request));

        return response()->json([
            'data' => $this->presenter->one($trigger->load('project')),
            'plan' => $this->presenter->plan($project->unsetRelation('triggers')),
            'requires_deploy' => true,
        ], 201);
    }

    // the answer adds what the change left to clean up (cleanup()), which the generator cannot follow
    #[BodyParameter('name', 'Unique in the project', type: 'string')]
    #[BodyParameter('enabled', type: 'bool')]
    #[BodyParameter('position', 'Place in the project\'s list (0–1000)', type: 'int')]
    #[BodyParameter('config', 'The type\'s settings to change (fields of GET /api/trigger-types)', type: 'array<string, mixed>')]
    #[BodyParameter('secrets', 'The type\'s write-only secrets to set', type: 'array<string, string>')]
    #[OpenApiResponse(200, 'The trigger, the plan of the project\'s triggers and what the change left to clean up', type: 'array{data: '.self::TRIGGER_TYPE.', plan: '.self::PLAN_TYPE.', requires_deploy: bool, cleanup?: list<'.self::CLEANUP_TYPE.'>}')]
    public function update(Request $request, Project $project, string $trigger): JsonResponse
    {
        $this->ensureSupported($project);
        $row = $this->find($project, $trigger);
        $row = $this->service->update($project, $row, $request->only(['name', 'enabled', 'position', 'config', 'secrets']));

        return response()->json([
            'data' => $this->presenter->one($row->load('project')),
            'plan' => $this->presenter->plan($project->unsetRelation('triggers')),
            'requires_deploy' => true,
        ] + $this->cleanup());
    }

    #[OpenApiResponse(200, 'The plan of the project\'s triggers and what the removal left to clean up', type: 'array{plan: '.self::PLAN_TYPE.', requires_deploy: bool, cleanup?: list<'.self::CLEANUP_TYPE.'>}')]
    public function destroy(Project $project, string $trigger): JsonResponse
    {
        $this->service->delete($project, $this->find($project, $trigger));

        return response()->json([
            'plan' => $this->presenter->plan($project->unsetRelation('triggers')),
            'requires_deploy' => true,
        ] + $this->cleanup());
    }

    #[BodyParameter('triggers', 'The whole list in its order, each entry as for POST; an entry whose name and type match an existing trigger updates it in place (its secrets, inbox URL and stored events stay)', required: true, type: 'list<array{type: string, name?: string, enabled?: bool, config?: array<string, mixed>, secrets?: array<string, string>}>')]
    #[OpenApiResponse(200, 'The triggers, their plan and what the change left to clean up', type: 'array{data: list<'.self::TRIGGER_TYPE.'>, plan: '.self::PLAN_TYPE.', requires_deploy: bool, cleanup?: list<'.self::CLEANUP_TYPE.'>}')]
    public function replace(Request $request, Project $project): JsonResponse
    {
        $this->ensureSupported($project);
        $request->validate(['triggers' => 'required|array|min:1|max:'.ProjectTrigger::MAX_PER_PROJECT]);
        $this->service->replace($project, array_values((array) $request->input('triggers')), self::userId($request));

        return response()->json([
            'data' => $this->presenter->list($project->unsetRelation('triggers')),
            'plan' => $this->presenter->plan($project),
            'requires_deploy' => true,
        ] + $this->cleanup());
    }

    /**
     * `cleanup`: what the change left on outside systems and RunR removes once the event gateway
     * no longer reads it (a cdc trigger's replication slots, CdcSlotCleanup) — absent when nothing.
     *
     * @return array<string, mixed>
     */
    private function cleanup(): array
    {
        $notes = app(CdcSlotCleanup::class)->takeNotes();

        return $notes === [] ? [] : ['cleanup' => $notes];
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }

    private function find(Project $project, string $trigger): ProjectTrigger
    {
        return $project->triggers()
            ->where(fn ($q) => $q->whereKey($trigger)->orWhere('name', $trigger))
            ->firstOrFail();
    }

    /** Rows of the retired AI types can be listed, not changed. */
    private function ensureSupported(Project $project): void
    {
        abort_unless($project->isSupportedType(), 422, __('api.project.service_type_unsupported'));
    }
}
