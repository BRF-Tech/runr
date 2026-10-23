<?php

namespace App\Triggers;

use App\Jobs\Triggers\NudgeEventGatewayJob;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\TriggerEvent;
use App\Triggers\Types\InboxTriggerType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RunR's own events as function triggers (docs/triggers.md#platform-events): a `platform_event`
 * trigger names the events it wants (config.events, "*" for all) and optionally the projects they
 * must be about (config.projects). dispatch() stores one trigger event per matching trigger of the
 * current organization; the project's event gateway delivers it as the CloudEvent
 * dev.runr.<event> (e.g. dev.runr.deployment.finished) with the payload as JSON data.
 *
 * Other features raise their events with
 *   app(PlatformEvents::class)->dispatch(PlatformEvents::COST_LIMIT_REACHED, [...], $projectId, $key);
 * where $key identifies the occurrence (a second dispatch with the same key is dropped).
 */
class PlatformEvents
{
    public const DEPLOYMENT_FINISHED = 'deployment.finished';

    public const DEPLOYMENT_FAILED = 'deployment.failed';

    public const ALERT_FIRED = 'alert.fired';

    public const ALERT_RESOLVED = 'alert.resolved';

    /** Raised by the runtime patch feed (feat/runtime-patches) when a patch for a project's runtime is published. */
    public const RUNTIME_PATCH_AVAILABLE = 'runtime.patch_available';

    /** Raised by cost limits (feat/cost-limit) when a project or organization reaches its limit. */
    public const COST_LIMIT_REACHED = 'cost.limit_reached';

    /**
     * Promotions between environments (docs/environments.md#notifications, PromotionNotifier): one
     * waits for an approver, was approved or rejected, was deployed or failed.
     */
    public const PROMOTION_PENDING_APPROVAL = 'environment.promotion.pending_approval';

    public const PROMOTION_APPROVED = 'environment.promotion.approved';

    public const PROMOTION_REJECTED = 'environment.promotion.rejected';

    public const PROMOTION_DEPLOYED = 'environment.promotion.deployed';

    public const PROMOTION_FAILED = 'environment.promotion.failed';

    public const ALL = [
        self::DEPLOYMENT_FINISHED,
        self::DEPLOYMENT_FAILED,
        self::ALERT_FIRED,
        self::ALERT_RESOLVED,
        self::RUNTIME_PATCH_AVAILABLE,
        self::COST_LIMIT_REACHED,
        self::PROMOTION_PENDING_APPROVAL,
        self::PROMOTION_APPROVED,
        self::PROMOTION_REJECTED,
        self::PROMOTION_DEPLOYED,
        self::PROMOTION_FAILED,
    ];

    public const TYPE_PREFIX = 'dev.runr.';

    public function __construct(private ProjectTriggers $triggers) {}

    /**
     * Store the event for every enabled platform_event trigger of the current organization that
     * wants it; returns how many triggers got it. Never throws: a failure is logged (the feature
     * that raised the event must not fail because of it).
     *
     * @param  array<string, mixed>  $data  the event payload (JSON data of the CloudEvent)
     * @param  string|null  $projectId  the project the event is about (config.projects filter, subject)
     * @param  string|null  $key  identity of the occurrence (dedupe); null: every dispatch is new
     */
    public function dispatch(string $event, array $data, ?string $projectId = null, ?string $key = null): int
    {
        try {
            // A tenant schema before TenantMigrations/0077 has no triggers to send it to
            if (! $this->triggers->tableExists(new Project)) {
                return 0;
            }

            return $this->store($event, $data, $projectId, $key);
        } catch (\Throwable $e) {
            Log::warning('Platform event dispatch failed', ['event' => $event, 'project' => $projectId, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    private function store(string $event, array $data, ?string $projectId, ?string $key): int
    {
        if (! in_array($event, self::ALL, true)) {
            throw new \InvalidArgumentException("Unknown platform event {$event}");
        }

        $subscribers = ProjectTrigger::query()
            ->where('type', 'platform_event')
            ->where('enabled', true)
            ->with('project')
            ->get()
            ->filter(fn (ProjectTrigger $t) => self::wants($t, $event, $projectId));

        $now = now();
        $payload = json_encode([
            'event' => $event,
            'project_id' => $projectId,
            'occurred_at' => $now->toIso8601String(),
            'data' => (object) $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stored = 0;
        foreach ($subscribers as $trigger) {
            if (! $trigger->project || $trigger->project->isStopped()) {
                continue;
            }

            // insertOrIgnore: the same occurrence again (unique trigger_id + delivery_id) is
            // dropped without an error that would abort the caller's transaction
            $inserted = TriggerEvent::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'project_id' => $trigger->project_id,
                'trigger_id' => $trigger->id,
                'kind' => TriggerEvent::KIND_PLATFORM,
                'event_type' => self::TYPE_PREFIX.$event,
                'subject' => $projectId,
                'delivery_id' => $key !== null ? mb_substr($event.':'.$key, 0, 255) : null,
                'content_type' => 'application/json',
                'body' => base64_encode((string) $payload),
                'body_size' => strlen((string) $payload),
                'status' => TriggerEvent::PENDING,
                'attempts' => 0,
                'replays' => 0,
                'received_at' => $now,
                'expires_at' => $now->copy()->addDays(InboxTriggerType::retentionDays($trigger)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted === 0) {
                continue;
            }

            $stored++;
            NudgeEventGatewayJob::nudge($trigger);
        }

        return $stored;
    }

    /** Whether the trigger subscribes to $event about $projectId. */
    public static function wants(ProjectTrigger $trigger, string $event, ?string $projectId): bool
    {
        $events = (array) ($trigger->setting('events') ?? []);
        if (! in_array('*', $events, true) && ! in_array($event, $events, true)) {
            return false;
        }

        $projects = $trigger->setting('projects');
        if (is_array($projects) && $projects !== []) {
            return $projectId !== null && in_array($projectId, $projects, true);
        }

        return true;
    }

    public static function isDuplicate(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? $e->getCode());

        // 23505 PostgreSQL unique_violation, 23000 MySQL / SQLite integrity constraint
        return in_array($code, ['23505', '23000'], true) || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
