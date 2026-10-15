<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\DeliverBillingEventsJob;
use Modules\Tenancy\Jobs\UsageThresholdJob;
use Modules\Tenancy\Models\BillingEvent;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\Billing\BillingEvents;
use Modules\Tenancy\Services\Plans\PlanService;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * Billing events (docs/billing.md): signed POSTs to the billing webhook with retries and backoff,
 * the event log, and billing.usage.threshold for usage amounts and plan limits.
 */
class BillingEventsTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private const URL = 'https://billing.example.com/hooks/runr';

    private const SECRET = 'whsec-test-0123456789abcdef';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(4)), self::WL_TENANT_MIGRATIONS);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function webhook(string $secret = self::SECRET): void
    {
        config(['tenancy.billing.webhook.url' => self::URL, 'tenancy.billing.webhook.secret' => $secret]);
    }

    private function events(): BillingEvents
    {
        return app(BillingEvents::class);
    }

    public function test_an_event_is_delivered_signed_with_the_webhook_secret(): void
    {
        $this->webhook();
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $event = $this->events()->record(BillingEvents::PLAN_CHANGED, (string) $this->tenant->id, ['source' => 'api']);

        $this->assertSame(BillingEvent::DELIVERED, $event->fresh()->delivery_status);
        $this->assertSame(1, $event->fresh()->attempts);

        Http::assertSent(function (Request $r) use ($event) {
            $timestamp = $r->header('X-RunR-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$r->body(), self::SECRET);
            $body = json_decode($r->body(), true);

            return $r->url() === self::URL
                && $r->header('X-RunR-Event')[0] === 'billing.plan.changed'
                && $r->header('X-RunR-Delivery')[0] === $event->id
                && $r->header('X-RunR-Signature')[0] === $expected
                && $body['id'] === $event->id
                && $body['type'] === 'billing.plan.changed'
                && $body['tenant']['slug'] === $this->tenant->slug
                && $body['data'] === ['source' => 'api'];
        });
    }

    public function test_without_a_secret_the_event_is_sent_unsigned(): void
    {
        $this->webhook('');
        Http::fake([self::URL => Http::response('', 204)]);

        $this->events()->record(BillingEvents::TEST, null, ['message' => 'hi']);

        Http::assertSent(fn (Request $r) => ! $r->hasHeader('X-RunR-Signature') && $r->hasHeader('X-RunR-Timestamp'));
    }

    public function test_failed_deliveries_back_off_stop_the_run_and_end_as_failed(): void
    {
        $this->webhook();
        $up = false;
        Http::fake(function () use (&$up) {
            return $up ? Http::response(['ok' => true]) : Http::response('down', 503);
        });

        $first = $this->events()->record(BillingEvents::USAGE_THRESHOLD, (string) $this->tenant->id, ['n' => 1]);
        $second = $this->events()->record(BillingEvents::USAGE_THRESHOLD, (string) $this->tenant->id, ['n' => 2]);
        Http::assertSentCount(2);   // one attempt each when they were recorded

        $first->refresh();
        $this->assertSame(BillingEvent::PENDING, $first->delivery_status);
        $this->assertSame(1, $first->attempts);
        $this->assertSame('HTTP 503', $first->last_error);
        $this->assertTrue($first->next_attempt_at->between(now()->addSeconds(50), now()->addSeconds(70)));

        // not due yet
        $this->assertSame(0, $this->events()->deliverPending());
        Http::assertSentCount(2);

        // due: the first fails again and the run stops before the second
        $this->travel(2)->minutes();
        $this->events()->deliverPending();
        Http::assertSentCount(3);
        $this->assertSame(2, $first->fresh()->attempts);
        $this->assertSame(1, $second->fresh()->attempts);

        // max_attempts (3 in this suite) → failed, no further retries
        $this->travel(6)->minutes();
        (new DeliverBillingEventsJob)->handle($this->events());
        $this->assertSame(BillingEvent::FAILED, $first->fresh()->delivery_status);
        $this->assertNull($first->fresh()->next_attempt_at);

        // queued again by hand once the receiver is back
        $up = true;
        $this->actingAs($this->admin())->postJson("/api/billing/events/{$first->id}/redeliver")
            ->assertOk()->assertJsonPath('event.delivery.status', BillingEvent::DELIVERED);
    }

    public function test_connection_errors_are_kept_on_the_event(): void
    {
        $this->webhook();
        Http::fake([self::URL => Http::failedConnection('Connection refused')]);

        $event = $this->events()->record(BillingEvents::TEST, null, []);

        $this->assertStringContainsString('Connection refused', (string) $event->fresh()->last_error);
        $this->assertSame(BillingEvent::PENDING, $event->fresh()->delivery_status);
    }

    public function test_the_test_endpoint_delivers_at_once(): void
    {
        $this->webhook();
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $this->actingAs($this->admin())->postJson('/api/billing/events/test')->assertOk()
            ->assertJsonPath('event.type', 'billing.test')
            ->assertJsonPath('event.delivery.status', 'delivered');
        Http::assertSentCount(1);
    }

    public function test_deduplicated_events_happen_once_and_old_events_are_pruned(): void
    {
        $this->assertNotNull($this->events()->record(BillingEvents::TEST, null, [], 'once'));
        $this->assertNull($this->events()->record(BillingEvents::TEST, null, [], 'once'));

        $old = $this->events()->record(BillingEvents::TEST, null, []);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();

        $this->assertSame(1, $this->events()->prune());
        $this->assertNull(BillingEvent::query()->find($old->id));
        $this->assertSame(1, BillingEvent::query()->count());
    }

    // ── Usage thresholds ────────────────────────────────────────────────────

    private function sessions(int $count, float $cost, string $currency = 'USD'): void
    {
        $this->tenant->run(function () use ($count, $cost, $currency) {
            $project = Project::query()->first() ?? Project::create(['name' => 'api', 'service_type' => 'caas']);
            UsageRecord::create([
                'project_id' => $project->id, 'deployment_id' => (string) Str::ulid(), 'region_id' => 'r1', 'record_date' => now()->toDateString(),
                'scale_mode' => 'scale_to_zero', 'entry_point' => 'http', 'session_count' => $count,
                'raw_cost' => $cost, 'multiplier' => 1, 'final_cost' => $cost, 'currency' => $currency,
            ]);
        });
    }

    private function thresholdEvents(): array
    {
        return BillingEvent::query()->where('type', BillingEvents::USAGE_THRESHOLD)->orderBy('id')->get()
            ->map(fn (BillingEvent $e) => $e->payload['data'])->all();
    }

    public function test_usage_amount_thresholds_are_announced_once_per_month(): void
    {
        config(['tenancy.billing.events.usage_thresholds' => '10,100']);
        $this->sessions(500, 50);

        dispatch_sync(new UsageThresholdJob);
        dispatch_sync(new UsageThresholdJob);

        $events = $this->thresholdEvents();
        $this->assertCount(1, $events);
        $this->assertEquals(['kind' => 'amount', 'period' => now()->format('Y-m'), 'currency' => 'USD', 'threshold' => 10, 'amount' => 50], $events[0]);

        $this->sessions(500, 60);
        dispatch_sync(new UsageThresholdJob);
        $this->assertEquals([10, 100], array_column($this->thresholdEvents(), 'threshold'));
    }

    public function test_plan_limit_thresholds_include_add_ons(): void
    {
        $this->enablePlans();
        app(PlanService::class)->assign($this->tenant, $this->plan(['price_monthly' => 0, 'invocations_per_month' => 1000]));
        $this->sessions(850, 0);

        dispatch_sync(new UsageThresholdJob);

        $events = $this->thresholdEvents();
        $this->assertCount(1, $events);
        $this->assertSame('limit', $events[0]['kind']);
        $this->assertSame('invocations_per_month', $events[0]['limit']);
        $this->assertSame(80, $events[0]['percent']);
        $this->assertSame(850, $events[0]['used']);
        $this->assertSame(1000, $events[0]['max']);

        $this->sessions(200, 0);
        dispatch_sync(new UsageThresholdJob);
        $this->assertSame([80, 100], array_column($this->thresholdEvents(), 'percent'));
    }

    public function test_nothing_is_checked_without_thresholds_or_plans(): void
    {
        $this->sessions(500, 5000);

        dispatch_sync(new UsageThresholdJob);

        $this->assertSame([], $this->thresholdEvents());
    }
}
