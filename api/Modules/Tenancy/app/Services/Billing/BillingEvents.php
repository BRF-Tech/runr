<?php

namespace Modules\Tenancy\Services\Billing;

use App\Services\Observability\Alerts\Channels\WebhookChannel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\DeliverBillingEventsJob;
use Modules\Tenancy\Models\BillingEvent;
use Modules\Tenancy\Models\Tenant;

/**
 * Billing events (docs/billing.md): recorded for GET /api/billing/events and POSTed to the billing
 * webhook when one is configured — signed like RunR's other webhooks:
 *
 *   X-RunR-Event: <type>        X-RunR-Delivery: <event id>        X-RunR-Timestamp: <unix seconds>
 *   X-RunR-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>
 *
 * Body: {id, type, created_at, tenant: {id, slug, name} | null, data}. A delivery succeeds on a 2xx
 * answer; otherwise it is retried with backoff (1 min … 24 h) up to tenancy.billing.webhook.max_attempts.
 */
class BillingEvents
{
    public const PERIOD_CLOSED = 'billing.period.closed';

    public const PLAN_CHANGED = 'billing.plan.changed';

    public const USAGE_THRESHOLD = 'billing.usage.threshold';

    public const CHARGE_VOIDED = 'billing.charge.voided';

    public const TEST = 'billing.test';

    public const TYPES = [self::PERIOD_CLOSED, self::PLAN_CHANGED, self::USAGE_THRESHOLD, self::CHARGE_VOIDED, self::TEST];

    /** Minutes to wait after the n-th failed attempt */
    private const BACKOFF_MINUTES = [1, 5, 15, 60, 180, 360, 720, 1440];

    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * Record an event. With $dedupeKey the event happens once: a second record() with the same key
     * returns null.
     */
    public function record(string $type, ?string $tenantId, array $data, ?string $dedupeKey = null): ?BillingEvent
    {
        if ($dedupeKey !== null && BillingEvent::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $id = (string) Str::ulid();
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId, ['id', 'slug', 'name']) : null;
        $webhook = $this->settings->webhookUrl() !== '';

        try {
            $event = BillingEvent::query()->create([
                'id' => $id,
                'type' => $type,
                'tenant_id' => $tenantId,
                'payload' => [
                    'id' => $id,
                    'type' => $type,
                    'created_at' => now()->toIso8601String(),
                    'tenant' => $tenantId === null ? null : [
                        'id' => $tenantId,
                        'slug' => $tenant?->slug,
                        'name' => $tenant?->name,
                    ],
                    'data' => $data,
                ],
                'dedupe_key' => $dedupeKey,
                'delivery_status' => $webhook ? BillingEvent::PENDING : BillingEvent::SKIPPED,
                'next_attempt_at' => $webhook ? now() : null,
            ]);
        } catch (QueryException $e) {
            // Another worker recorded the same deduplicated event a moment ago
            if ($dedupeKey !== null && BillingEvent::query()->where('dedupe_key', $dedupeKey)->exists()) {
                return null;
            }

            throw $e;
        }

        if ($webhook) {
            DeliverBillingEventsJob::dispatch()->afterCommit();
        }

        return $event;
    }

    /**
     * Deliver due events, oldest first. A failed delivery ends the run (the endpoint is down — the
     * remaining events wait for the next run).
     *
     * @return int delivered events
     */
    public function deliverPending(int $limit = 100): int
    {
        if ($this->settings->webhookUrl() === '') {
            return 0;
        }

        $delivered = 0;
        $due = BillingEvent::query()
            ->where('delivery_status', BillingEvent::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $event) {
            if (! $this->deliver($event)) {
                break;
            }
            $delivered++;
        }

        return $delivered;
    }

    /**
     * One delivery attempt. true when the receiver answered 2xx.
     */
    public function deliver(BillingEvent $event): bool
    {
        $url = $this->settings->webhookUrl();
        if ($url === '') {
            return false;
        }

        $body = (string) json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $secret = $this->settings->webhookSecret();

        $headers = [
            'X-RunR-Event' => $event->type,
            'X-RunR-Delivery' => $event->id,
            'X-RunR-Timestamp' => $timestamp,
        ];
        if ($secret !== '') {
            $headers['X-RunR-Signature'] = 'sha256='.WebhookChannel::signature($secret, $timestamp, $body);
        }

        $error = null;
        try {
            $response = Http::timeout(max(1, (int) config('tenancy.billing.webhook.timeout', 10)))
                ->withOptions(['allow_redirects' => false])
                ->withUserAgent('RunR-Billing/1')
                ->acceptJson()
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                $event->forceFill([
                    'delivery_status' => BillingEvent::DELIVERED,
                    'attempts' => $event->attempts + 1,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                    'last_error' => null,
                ])->save();

                return true;
            }

            $error = 'HTTP '.$response->status();
        } catch (ConnectionException $e) {
            $error = $e->getMessage();
        }

        $attempts = $event->attempts + 1;
        $final = $attempts >= max(1, (int) config('tenancy.billing.webhook.max_attempts', 10));
        $event->forceFill([
            'delivery_status' => $final ? BillingEvent::FAILED : BillingEvent::PENDING,
            'attempts' => $attempts,
            'next_attempt_at' => $final ? null : now()->addMinutes(self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1]),
            'last_error' => mb_substr((string) $error, 0, 1000),
        ])->save();

        Log::warning('Billing event delivery failed', ['event' => $event->id, 'type' => $event->type, 'attempts' => $attempts, 'error' => $error]);

        return false;
    }

    /**
     * Queue an event for delivery again (failed, skipped or delivered).
     */
    public function redeliver(BillingEvent $event): BillingEvent
    {
        $event->forceFill([
            'delivery_status' => BillingEvent::PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'last_error' => null,
        ])->save();

        DeliverBillingEventsJob::dispatch()->afterCommit();

        return $event;
    }

    /**
     * Remove events older than the retention (tenancy.billing.events.retention_days).
     */
    public function prune(): int
    {
        $days = max(1, (int) config('tenancy.billing.events.retention_days', 90));

        return BillingEvent::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
