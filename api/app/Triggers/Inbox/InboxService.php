<?php

namespace App\Triggers\Inbox;

use App\Jobs\Triggers\NudgeEventGatewayJob;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\TriggerEvent;
use App\Triggers\Types\InboxTriggerType;
use App\Triggers\Types\WebhookTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The stored events of webhook and platform_event triggers (trigger_events) and the inbox the
 * project's event gateway pulls them from (docs/triggers.md#delivery):
 *
 *  - claim: pending events of an enabled trigger — and events whose lease ran out (a gateway that
 *    stopped mid-delivery) — become `delivering` with a lease; the gateway delivers each as a
 *    CloudEvent with retries and backoff
 *  - ack: `delivered`, or `failed` once the retries are used up (the trigger's dead letter);
 *    an ack for an event that is no longer `delivering` (replayed meanwhile) is ignored
 *  - replay: the event is `pending` again and the gateway is nudged
 *  - prune: events past their retention go
 */
class InboxService
{
    /** Headers never stored or forwarded. */
    private const DROPPED_HEADERS = ['authorization', 'cookie', 'proxy-authorization', 'x-xsrf-token'];

    private const MAX_HEADERS = 64;

    private const MAX_HEADER_BYTES = 4096;

    /**
     * Store a webhook request. Returns [event, false], or [existing event, true] when the
     * sender's delivery id is already stored (a redelivery: the sender gets 200 again, nothing is
     * delivered twice).
     *
     * @return array{0: TriggerEvent, 1: bool}
     */
    public function storeWebhook(ProjectTrigger $trigger, Request $request, string $body, ?string $deliveryId, ?string $subject): array
    {
        if ($deliveryId !== null && ($existing = $trigger->events()->where('delivery_id', $deliveryId)->first())) {
            return [$existing, true];
        }

        $now = now();
        $id = (string) Str::ulid();
        $inserted = TriggerEvent::query()->insertOrIgnore([
            'id' => $id,
            'project_id' => $trigger->project_id,
            'trigger_id' => $trigger->id,
            'kind' => TriggerEvent::KIND_WEBHOOK,
            'event_type' => WebhookTrigger::EVENT_TYPE,
            'subject' => $subject,
            'delivery_id' => $deliveryId,
            'content_type' => mb_substr((string) ($request->header('Content-Type') ?: 'application/octet-stream'), 0, 255),
            'headers' => json_encode(self::headers($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'body' => base64_encode($body),
            'body_size' => strlen($body),
            'status' => TriggerEvent::PENDING,
            'attempts' => 0,
            'replays' => 0,
            'received_at' => $now,
            'expires_at' => $now->copy()->addDays(InboxTriggerType::retentionDays($trigger)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Lost a race with the same delivery id
        if ($inserted === 0 && $deliveryId !== null && ($existing = $trigger->events()->where('delivery_id', $deliveryId)->first())) {
            return [$existing, true];
        }

        NudgeEventGatewayJob::nudge($trigger);

        return [TriggerEvent::query()->findOrFail($id), false];
    }

    /**
     * Claim up to $limit deliverable events of the project's trigger for $leaseSeconds.
     *
     * @return list<array<string, mixed>> the events as the gateway's inbox source reads them
     */
    public function claim(Project $project, string $triggerName, int $limit, int $leaseSeconds): array
    {
        $trigger = $this->trigger($project, $triggerName);
        if (! $trigger || ! $trigger->enabled) {
            return [];
        }

        $limit = max(1, min($limit, 100));
        $leaseSeconds = max(30, min($leaseSeconds, 6 * 3600));
        $now = now();

        /** @var Collection<int, TriggerEvent> $claimed */
        $claimed = DB::connection($trigger->getConnectionName())->transaction(function () use ($trigger, $limit, $leaseSeconds, $now) {
            $events = TriggerEvent::query()
                ->where('trigger_id', $trigger->id)
                ->where(fn ($q) => $q->where('status', TriggerEvent::PENDING)
                    ->orWhere(fn ($q) => $q->where('status', TriggerEvent::DELIVERING)->where('lease_until', '<', $now)))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->orderBy('received_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            foreach ($events as $event) {
                $event->forceFill([
                    'status' => TriggerEvent::DELIVERING,
                    'lease_until' => $now->copy()->addSeconds($leaseSeconds),
                    'attempts' => $event->attempts + 1,
                ])->save();
            }

            return $events;
        });

        return $claimed->map(fn (TriggerEvent $e) => $this->toGatewayEvent($trigger, $e))->values()->all();
    }

    /**
     * Record the gateway's outcomes: [{id, status: delivered|failed, error?}]. Returns how many
     * events changed.
     *
     * @param  list<array<string, mixed>>  $results
     */
    public function ack(Project $project, string $triggerName, array $results): int
    {
        $trigger = $this->trigger($project, $triggerName);
        if (! $trigger) {
            return 0;
        }

        $changed = 0;
        foreach ($results as $result) {
            $id = is_string($result['id'] ?? null) ? $result['id'] : null;
            $status = $result['status'] ?? null;
            if ($id === null || ! in_array($status, [TriggerEvent::DELIVERED, TriggerEvent::FAILED], true)) {
                continue;
            }

            $changed += TriggerEvent::query()
                ->whereKey($id)
                ->where('trigger_id', $trigger->id)
                ->where('status', TriggerEvent::DELIVERING)
                ->update([
                    'status' => $status,
                    'lease_until' => null,
                    'delivered_at' => $status === TriggerEvent::DELIVERED ? now() : null,
                    'last_error' => $status === TriggerEvent::FAILED ? mb_substr((string) ($result['error'] ?? ''), 0, 1000) ?: null : null,
                    'updated_at' => now(),
                ]);
        }

        return $changed;
    }

    /** Deliver a stored event again (whatever its status); the gateway is nudged. */
    public function replay(TriggerEvent $event): TriggerEvent
    {
        $event->forceFill([
            'status' => TriggerEvent::PENDING,
            'lease_until' => null,
            'attempts' => 0,
            'replays' => $event->replays + 1,
            'last_error' => null,
            'delivered_at' => null,
        ])->save();

        NudgeEventGatewayJob::nudge($event->trigger);

        return $event;
    }

    /** Delete events past their retention; returns how many. */
    public function prune(): int
    {
        return TriggerEvent::query()->where('expires_at', '<', now())->delete();
    }

    /** The event as the gateway's inbox source reads it (claim response). */
    public function toGatewayEvent(ProjectTrigger $trigger, TriggerEvent $event): array
    {
        $extensions = [];
        if ($event->delivery_id !== null) {
            $extensions['runrdelivery'] = $event->delivery_id;
        }
        if ($event->kind === TriggerEvent::KIND_WEBHOOK && $event->headers) {
            $extensions['runrheaders'] = (string) json_encode($event->headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($event->replays > 0) {
            $extensions['runrreplay'] = (string) $event->replays;
        }

        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'source' => $event->kind === TriggerEvent::KIND_WEBHOOK ? '/webhooks/'.$trigger->name : '/runr/platform',
            'subject' => $event->subject,
            'time' => ($event->received_at ?? $event->created_at)?->toIso8601ZuluString('microsecond'),
            'datacontenttype' => $event->content_type ?: 'application/octet-stream',
            'data_base64' => (string) $event->body,
            'extensions' => (object) $extensions,
            // claims before this one: the gateway counts them against the trigger's retries
            'attempts' => max(0, $event->attempts - 1),
        ];
    }

    private function trigger(Project $project, string $name): ?ProjectTrigger
    {
        $trigger = $project->triggers()->where('name', $name)->first();

        return $trigger && $trigger->handler()?->storesEvents() ? $trigger : null;
    }

    /**
     * The request's headers worth keeping: lower-case names, first value, credentials dropped,
     * at most 64 of at most 4 KiB.
     *
     * @return array<string, string>
     */
    public static function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            if (in_array($name, self::DROPPED_HEADERS, true) || $values === []) {
                continue;
            }
            $headers[$name] = mb_substr((string) $values[0], 0, self::MAX_HEADER_BYTES);
            if (count($headers) >= self::MAX_HEADERS) {
                break;
            }
        }
        ksort($headers);

        return $headers;
    }
}
