<?php

namespace App\Services\Observability\Alerts;

use App\Jobs\DeliverAlertJob;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Services\Observability\Alerts\Channels\ChannelRegistry;
use App\Services\Observability\TenantScope;
use Illuminate\Support\Collection;

/**
 * Fires and resolves alerts of a rule with deduplication and cooldown, and routes them to the
 * rule's channels (one AlertDelivery per channel, sent with retries by DeliverAlertJob).
 *
 * fingerprint = "<rule id>:<subject>" (subject: project id, region + resource, uptime check id):
 *  - not firing yet  → a new event, delivered to every channel
 *  - already firing  → deduplicated; delivered again only once the cooldown since the last
 *                      notification has passed (still-firing reminder)
 *  - resolve         → the firing event is closed and the channels get a resolve message
 */
class AlertManager
{
    public function __construct(private ChannelRegistry $channels) {}

    /**
     * @param  array<string, mixed>  $data  fields of the message (project, region, value, threshold, url …)
     */
    public function fire(AlertRule $rule, string $subject, string $title, string $message, array $data = [], ?string $projectId = null): ?AlertEvent
    {
        $fingerprint = self::fingerprint($rule, $subject);
        $now = now();

        $event = AlertEvent::query()
            ->where('fingerprint', $fingerprint)
            ->where('status', AlertEvent::FIRING)
            ->latest('fired_at')
            ->first();

        $rule->forceFill(['state' => AlertRule::STATE_FIRING, 'last_fired_at' => $now])->save();

        if ($event) {
            $event->forceFill(['message' => $message, 'data' => $data, 'severity' => $rule->severity])->save();

            $cooldown = max(0, (int) $rule->cooldown_minutes);
            if ($event->last_notified_at && $event->last_notified_at->copy()->addMinutes($cooldown)->isFuture()) {
                return null; // deduplicated
            }

            $event->forceFill(['last_notified_at' => $now])->save();
            $this->deliver($event, $rule, AlertDelivery::KIND_ALERT);

            return $event;
        }

        $event = AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'fingerprint' => $fingerprint,
            'type' => $rule->type,
            'severity' => $rule->severity,
            'status' => AlertEvent::FIRING,
            'title' => mb_substr($title, 0, 255),
            'message' => $message,
            'data' => $data,
            'project_id' => $projectId,
            'fired_at' => $now,
            'last_notified_at' => $now,
        ]);

        $this->deliver($event, $rule, AlertDelivery::KIND_ALERT);

        return $event;
    }

    /**
     * Close the firing event of the subject (if any) and send the resolve message.
     */
    public function resolve(AlertRule $rule, string $subject, ?string $message = null): ?AlertEvent
    {
        $event = AlertEvent::query()
            ->where('fingerprint', self::fingerprint($rule, $subject))
            ->where('status', AlertEvent::FIRING)
            ->latest('fired_at')
            ->first();

        if (! $event) {
            return null;
        }

        $data = $event->data ?? [];
        if ($message) {
            $data['resolve_message'] = $message;
        }
        $event->forceFill(['status' => AlertEvent::RESOLVED, 'resolved_at' => now(), 'data' => $data])->save();

        $stillFiring = AlertEvent::query()->where('alert_rule_id', $rule->id)->where('status', AlertEvent::FIRING)->exists();
        if (! $stillFiring) {
            $rule->forceFill(['state' => AlertRule::STATE_OK])->save();
        }

        $this->deliver($event, $rule, AlertDelivery::KIND_RESOLVE);

        return $event;
    }

    /** Enabled rules of a type that apply to the project (project rules + all-project rules). */
    public function rulesFor(string $type, ?string $projectId = null): Collection
    {
        return AlertRule::query()
            ->where('type', $type)
            ->where('is_enabled', true)
            ->when($projectId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('project_id')->orWhere('project_id', $projectId)))
            ->get();
    }

    /** One delivery per enabled channel of the rule, sent in the background. */
    public function deliver(AlertEvent $event, AlertRule $rule, string $kind): void
    {
        $ids = array_values(array_filter((array) ($rule->channel_ids ?? []), 'is_string'));
        if (! $ids) {
            return;
        }

        $tenant = TenantScope::current();
        foreach (AlertChannel::query()->whereIn('id', $ids)->where('is_enabled', true)->get() as $channel) {
            $delivery = AlertDelivery::query()->create([
                'alert_event_id' => $event->id,
                'alert_channel_id' => $channel->id,
                'kind' => $kind,
                'status' => AlertDelivery::PENDING,
            ]);

            DeliverAlertJob::dispatch($delivery->id, $tenant);
        }
    }

    /**
     * Send one delivery now: the outcome is recorded on the delivery. Returns whether it was sent.
     */
    public function send(AlertDelivery $delivery): Channels\DeliveryResult
    {
        $channel = $delivery->channel;
        if (! $channel) {
            $delivery->forceFill(['status' => AlertDelivery::FAILED, 'last_error' => 'channel deleted'])->save();

            return Channels\DeliveryResult::failed('channel deleted', false);
        }

        $message = $delivery->kind === AlertDelivery::KIND_TEST || ! $delivery->event
            ? AlertMessage::test($channel->name)
            : AlertMessage::fromEvent($delivery->event, $delivery->kind);

        try {
            $result = $this->channels->for($channel->type)->send($channel, $message);
        } catch (\Throwable $e) {
            $result = Channels\DeliveryResult::failed($e->getMessage());
        }

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'response_code' => $result->code,
            'last_error' => $result->ok ? null : $result->error,
            'status' => $result->ok ? AlertDelivery::SENT : $delivery->status,
            'sent_at' => $result->ok ? now() : $delivery->sent_at,
        ])->save();

        return $result;
    }

    /**
     * "Send test" of a channel: synchronous, recorded in the delivery log and on the channel.
     */
    public function sendTest(AlertChannel $channel): AlertDelivery
    {
        $delivery = AlertDelivery::query()->create([
            'alert_event_id' => null,
            'alert_channel_id' => $channel->id,
            'kind' => AlertDelivery::KIND_TEST,
            'status' => AlertDelivery::PENDING,
        ]);
        $delivery->setRelation('channel', $channel);

        $result = $this->send($delivery);
        if (! $result->ok) {
            $delivery->forceFill(['status' => AlertDelivery::FAILED])->save();
        }

        $channel->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $result->ok,
            'last_test_error' => $result->ok ? null : mb_substr((string) $result->error, 0, 500),
        ])->save();

        return $delivery->refresh();
    }

    public static function fingerprint(AlertRule $rule, string $subject): string
    {
        return mb_substr($rule->id.':'.$subject, 0, 191);
    }
}
