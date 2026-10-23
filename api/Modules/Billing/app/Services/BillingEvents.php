<?php

namespace Modules\Billing\Services;

use App\Services\Dns\DnsResolver;
use App\Services\Observability\Alerts\Channels\WebhookChannel;
use App\Services\Observability\OutboundUrlGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Jobs\DeliverBillingEventsJob;
use Modules\Billing\Models\BillingEvent;
use Modules\Billing\Support\BillingContext;

/**
 * Billing events (docs/billing.md#webhook-events): recorded in the organization's event log
 * (GET /api/billing/events) and POSTed to its billing webhook when one is configured — signed like
 * RunR's other webhooks:
 *
 *   X-RunR-Event: <type>        X-RunR-Delivery: <event id>        X-RunR-Timestamp: <unix seconds>
 *   X-RunR-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>
 *
 * Body: {id, type, created_at, organization: {kind, id, slug, name}, data}. A 2xx answer delivers;
 * anything else is retried with backoff (1 min … 24 h) up to billing.webhook.max_attempts. Every
 * delivery checks the URL first (urlProblem(): no private or cluster-internal targets).
 */
class BillingEvents
{
    public const BILL_CLOSED = 'bill.closed';

    public const BILL_PAID = 'bill.paid';

    public const BILL_UNPAID = 'bill.unpaid';

    public const BILL_PAYMENT_URL_SET = 'bill.payment_url_set';

    public const FIXED_REQUEST_CREATED = 'fixed_request.created';

    public const FIXED_REQUEST_APPROVED = 'fixed_request.approved';

    public const FIXED_REQUEST_REJECTED = 'fixed_request.rejected';

    public const REGION_SHARE_OFFERED = 'region_share.offered';

    public const REGION_SHARE_ACCEPTED = 'region_share.accepted';

    public const PROJECT_STOPPED = 'project.stopped';

    public const PROJECT_RESUMED = 'project.resumed';

    public const PROJECT_OWNER_CHANGED = 'project.owner_changed';

    /** A project's month to date reached the warning share of its monthly cost limit (CostLimits). */
    public const PROJECT_COST_LIMIT_WARNING = 'project.cost_limit.warning';

    /** A project's month to date reached its monthly cost limit; data.stopped: the limit stopped it. */
    public const PROJECT_COST_LIMIT_REACHED = 'project.cost_limit.reached';

    // API product (docs/api-product.md#usage-events): usage of a gateway's consumers, for the
    // system that invoices them — RunR measures, it does not bill
    public const API_USAGE_HOURLY = 'api.usage.hourly';

    public const API_USAGE_MONTHLY = 'api.usage.monthly';

    public const API_USAGE_QUOTA_EXCEEDED = 'api.usage.quota_exceeded';

    public const TEST = 'billing.test';

    public const TYPES = [
        self::BILL_CLOSED, self::BILL_PAID, self::BILL_UNPAID, self::BILL_PAYMENT_URL_SET,
        self::FIXED_REQUEST_CREATED, self::FIXED_REQUEST_APPROVED, self::FIXED_REQUEST_REJECTED,
        self::REGION_SHARE_OFFERED, self::REGION_SHARE_ACCEPTED,
        self::PROJECT_STOPPED, self::PROJECT_RESUMED, self::PROJECT_OWNER_CHANGED,
        self::PROJECT_COST_LIMIT_WARNING, self::PROJECT_COST_LIMIT_REACHED,
        self::API_USAGE_HOURLY, self::API_USAGE_MONTHLY, self::API_USAGE_QUOTA_EXCEEDED,
        self::TEST,
    ];

    /** Minutes to wait after the n-th failed attempt */
    private const BACKOFF_MINUTES = [1, 5, 15, 60, 180, 360, 720, 1440];

    public function __construct(
        private readonly BillingSettings $settings,
        private readonly OutboundUrlGuard $guard,
        private readonly DnsResolver $dns,
    ) {}

    /**
     * Null when the organization may call $url as its webhook, else the reason (for the user: no
     * internal detail). Like the alert webhooks (OutboundUrlGuard): http(s), no credentials, no
     * private, loopback or cluster-internal address. A tenant's webhook is always held to that; the
     * platform's (or the installation's) follows observability.alerts.allow_private_targets, and
     * BILLING_WEBHOOK_URL — set by the operator, not through the API — may be internal.
     */
    public function urlProblem(string $url, bool $fromEnvironment = false): ?string
    {
        return $this->guard->check($url, $this->allowsPrivate($fromEnvironment));
    }

    private function allowsPrivate(bool $fromEnvironment): bool
    {
        if (BillingContext::inTenant()) {
            return false;
        }

        return $fromEnvironment || (bool) config('observability.alerts.allow_private_targets', false);
    }

    /**
     * Where a delivery may go: the reason it may not, or the request options that pin the host to
     * the addresses that were checked (a name cannot resolve to a private address between the check
     * and the request).
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function target(string $url): array
    {
        $fromEnvironment = $this->settings->webhookUrlSource() === 'config';
        $reason = $this->urlProblem($url, $fromEnvironment);
        if ($reason !== null || $this->allowsPrivate($fromEnvironment)) {
            return [$reason, []];
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [null, []];
        }
        $addresses = $this->dns->addresses($host);
        if ($addresses === []) {
            return [__('observability.url.unresolvable'), []];
        }
        foreach ($addresses as $ip) {
            if (! OutboundUrlGuard::isPublicIp($ip)) {
                return [__('observability.url.private'), []];
            }
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        $ip = $addresses[0];

        return [null, ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]]]];
    }

    /** A delivery error as stored and shown: a tenant sees what happened, not where the request went. */
    private function connectionError(ConnectionException $e): string
    {
        if (! BillingContext::inTenant()) {
            return $e->getMessage();
        }

        return __(str_contains(strtolower($e->getMessage()), 'timed out') ? 'billing.webhook_timeout' : 'billing.webhook_unreachable');
    }

    /**
     * Record an event in the current organization's log. With $dedupeKey the event happens once.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(string $type, array $data, ?string $dedupeKey = null): ?BillingEvent
    {
        return $this->store(null, $type, $data, $dedupeKey, BillingContext::organization(), BillingContext::tenantKey());
    }

    /**
     * Record an event in the platform's log (central), e.g. a tenant accepting a shared region —
     * also when the request runs inside the tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordCentral(string $type, ?string $tenantId, array $data, ?string $dedupeKey = null): ?BillingEvent
    {
        if (! BillingContext::inTenant()) {
            return $this->store(null, $type, $data, $dedupeKey, BillingContext::organization(), $tenantId);
        }

        $tenant = tenancy()->tenant;
        tenancy()->end();
        try {
            return $this->store(null, $type, $data, $dedupeKey, BillingContext::organization(), $tenantId);
        } finally {
            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $organization
     */
    private function store(?string $connection, string $type, array $data, ?string $dedupeKey, array $organization, ?string $tenantId): ?BillingEvent
    {
        try {
            if ($dedupeKey !== null && BillingEvent::on($connection)->where('dedupe_key', $dedupeKey)->exists()) {
                return null;
            }

            $id = (string) Str::ulid();
            $webhook = $this->settings->webhookUrl() !== '';

            // In a savepoint of its own: a duplicate (another worker recorded the same deduplicated
            // event) does not abort the transaction a bill is closed in
            $event = BillingEvent::on($connection)->getConnection()->transaction(fn () => BillingEvent::on($connection)->create([
                'id' => $id,
                'type' => $type,
                'tenant_id' => $tenantId,
                'payload' => [
                    'id' => $id,
                    'type' => $type,
                    'created_at' => now()->toIso8601String(),
                    'organization' => $organization,
                    'data' => $data,
                ],
                'dedupe_key' => $dedupeKey,
                'delivery_status' => $webhook ? BillingEvent::PENDING : BillingEvent::SKIPPED,
                'next_attempt_at' => $webhook ? now() : null,
            ]));
        } catch (QueryException $e) {
            // Another worker recorded the same deduplicated event a moment ago
            if ($dedupeKey !== null) {
                return null;
            }
            Log::warning('Billing event not recorded', ['type' => $type, 'error' => $e->getMessage()]);

            return null;
        }

        if ($webhook) {
            DeliverBillingEventsJob::dispatch(true)->afterCommit();
        }

        return $event;
    }

    /**
     * Deliver the organization's due events, oldest first. A failed delivery ends the run (the
     * endpoint is down — the remaining events wait for the next run).
     */
    public function deliverPending(int $limit = 100): int
    {
        if ($this->settings->webhookUrl() === '') {
            return 0;
        }

        try {
            $due = BillingEvent::query()
                ->where('delivery_status', BillingEvent::PENDING)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')
                ->limit($limit)
                ->get();
        } catch (QueryException) {
            return 0; // billing tables not migrated in this organization yet
        }

        $delivered = 0;
        foreach ($due as $event) {
            if (! $this->deliver($event)) {
                break;
            }
            $delivered++;
        }

        return $delivered;
    }

    /** One delivery attempt; true when the receiver answered 2xx. */
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

        // Checked before every delivery, not only when the URL was saved: DNS changes
        [$error, $options] = $this->target($url);
        if ($error === null) {
            try {
                $response = Http::timeout(max(1, (int) config('billing.webhook.timeout', 10)))
                    ->withOptions(['allow_redirects' => false] + $options)
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
                $error = $this->connectionError($e);
            }
        }

        $attempts = $event->attempts + 1;
        $final = $attempts >= max(1, (int) config('billing.webhook.max_attempts', 10));
        $event->forceFill([
            'delivery_status' => $final ? BillingEvent::FAILED : BillingEvent::PENDING,
            'attempts' => $attempts,
            'next_attempt_at' => $final ? null : now()->addMinutes(self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1]),
            'last_error' => mb_substr((string) $error, 0, 1000),
        ])->save();

        Log::warning('Billing event delivery failed', ['event' => $event->id, 'type' => $event->type, 'attempts' => $attempts, 'error' => $error]);

        return false;
    }

    /** Queue an event for delivery again (failed, skipped or delivered). */
    public function redeliver(BillingEvent $event): BillingEvent
    {
        $event->forceFill([
            'delivery_status' => BillingEvent::PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'last_error' => null,
        ])->save();

        DeliverBillingEventsJob::dispatch(true)->afterCommit();

        return $event;
    }

    /** Remove the organization's events older than billing.events.retention_days. */
    public function prune(): int
    {
        $days = max(1, (int) config('billing.events.retention_days', 90));

        try {
            return BillingEvent::query()->where('created_at', '<', now()->subDays($days))->delete();
        } catch (QueryException) {
            return 0;
        }
    }
}
