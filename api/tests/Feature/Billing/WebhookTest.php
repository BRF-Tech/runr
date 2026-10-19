<?php

namespace Tests\Feature\Billing;

use App\Events\ProjectOwnerChanged;
use App\Events\ProjectStopped;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Jobs\DeliverBillingEventsJob;
use Modules\Billing\Models\BillingEvent;
use Modules\Billing\Models\BillingSetting;
use Modules\Billing\Services\BillingEvents;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Billing events and the signed webhook (docs/billing.md#webhook-events): the event log, signed
 * delivery, retries with backoff, redelivery, the test event and the webhook settings.
 */
class WebhookTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    private const SECRET = 'a-webhook-secret-of-32-characters';

    /** @var array<string, list<string>> host → addresses of the fake resolver */
    private array $dns = [
        'other.example.com' => ['93.184.215.14'],
        'hooks.example.com' => ['93.184.215.15'],
        'rebind.example.com' => ['93.184.215.16', '10.0.0.7'],
        'internal.example.com' => ['127.0.0.1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->setUpBilling(['webhook' => ['url' => 'https://invoicing.example.com/hooks/runr', 'secret' => self::SECRET]]);

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly WebhookTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->resolve($name);
            }
        });
    }

    /** @return list<string> */
    public function resolve(string $name): array
    {
        return $this->dns[$name] ?? [];
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_events_are_delivered_signed(): void
    {
        Http::fake(['invoicing.example.com/*' => Http::response('', 204)]);

        $event = app(BillingEvents::class)->record(BillingEvents::TEST, ['message' => 'hello']);

        Http::assertSent(function (ClientRequest $request) use ($event) {
            $timestamp = $request->header('X-RunR-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET);

            return $request->url() === 'https://invoicing.example.com/hooks/runr'
                && $request->header('X-RunR-Event')[0] === 'billing.test'
                && $request->header('X-RunR-Delivery')[0] === $event->id
                && $request->header('X-RunR-Signature')[0] === $expected
                && $request['organization']['kind'] === 'install'
                && $request['data']['message'] === 'hello';
        });
        $this->assertSame(BillingEvent::DELIVERED, $event->fresh()->delivery_status);
    }

    public function test_a_failed_delivery_is_retried_with_backoff_and_can_be_redelivered(): void
    {
        $admin = $this->userWithRole('owner');
        Http::fake(['invoicing.example.com/*' => Http::sequence()->push('down', 500)->push('down', 500)->push('down', 500)->push('', 200)]);

        $event = app(BillingEvents::class)->record(BillingEvents::TEST, ['message' => 'x']);
        $event->refresh();
        $this->assertSame(BillingEvent::PENDING, $event->delivery_status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('HTTP 500', $event->last_error);
        $this->assertTrue($event->next_attempt_at->equalTo(now()->addMinute()));

        // Not due yet: nothing is sent
        dispatch_sync(new DeliverBillingEventsJob);
        $this->assertSame(1, $event->fresh()->attempts);

        Carbon::setTestNow(now()->addMinutes(2));
        dispatch_sync(new DeliverBillingEventsJob);
        Carbon::setTestNow(now()->addMinutes(6));
        dispatch_sync(new DeliverBillingEventsJob);
        // max_attempts 3: failed for good
        $this->assertSame(BillingEvent::FAILED, $event->fresh()->delivery_status);

        $this->actingAs($admin)->postJson("/api/billing/events/{$event->id}/redeliver")->assertOk();
        $this->assertSame(BillingEvent::DELIVERED, $event->fresh()->delivery_status);

        $this->actingAs($admin)->getJson('/api/billing/events?order=desc&limit=1')->assertOk()
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.delivery.status', 'delivered')
            ->assertJsonPath('meta.webhook_configured', true);
    }

    public function test_the_test_event_and_the_settings(): void
    {
        $admin = $this->userWithRole('owner');
        Http::fake(['*' => Http::response('', 200)]);

        $this->actingAs($admin)->postJson('/api/billing/events/test')->assertOk()->assertJsonPath('delivered', true);

        $this->actingAs($admin)->getJson('/api/billing/settings')->assertOk()
            ->assertJsonPath('context', 'install')
            ->assertJsonPath('webhook.url_source', 'config')
            ->assertJsonPath('webhook.secret_set', true);

        // A stored secret is never returned; clearing the URL there brings the environment's back
        $this->actingAs($admin)->putJson('/api/billing/settings', ['webhook_url' => 'https://other.example.com/hook', 'webhook_secret' => 'another-secret-value-1234'])
            ->assertOk()->assertJsonPath('webhook.url', 'https://other.example.com/hook')->assertJsonPath('webhook.url_source', 'settings')
            ->assertJsonMissingPath('webhook.secret');
        $this->assertStringNotContainsString('another-secret', (string) BillingSetting::query()->find('webhook_secret')->value);

        $this->actingAs($admin)->putJson('/api/billing/settings', ['webhook_url' => null, 'clear_secrets' => ['webhook_secret']])
            ->assertOk()->assertJsonPath('webhook.url_source', 'config');

        // No webhook at all: the test event is refused, events are only logged
        config(['billing.webhook.url' => '']);
        $this->actingAs($admin)->postJson('/api/billing/events/test')->assertStatus(409)->assertJsonPath('code', 'webhook_not_configured');
        $skipped = app(BillingEvents::class)->record(BillingEvents::TEST, []);
        $this->assertSame(BillingEvent::SKIPPED, $skipped->delivery_status);
    }

    public function test_project_events_of_the_core_become_billing_events(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $owner = $this->userWithRole('owner');
        $other = $this->userWithRole('developer');
        $project = $this->billedProject('api', $this->billingRegion(), $owner);

        event(new ProjectStopped($project, 'unpaid since March', (string) $owner->id));
        event(new ProjectOwnerChanged($project, (string) $owner->id, (string) $other->id, (string) $owner->id));

        $stopped = BillingEvent::query()->where('type', 'project.stopped')->first();
        $this->assertSame('unpaid since March', $stopped->payload['data']['reason']);
        $this->assertSame((string) $other->id, BillingEvent::query()->where('type', 'project.owner_changed')->first()->payload['data']['owner_id']);
    }

    public function test_a_webhook_to_a_private_or_internal_address_is_refused(): void
    {
        $admin = $this->userWithRole('owner');

        foreach (['http://10.0.0.5:8080/hook', 'http://127.0.0.1/', 'https://internal.example.com/hook', 'https://rebind.example.com/hook',
            'http://runr-api.runr.svc.cluster.local/api', 'https://user:pass@hooks.example.com/', 'ftp://hooks.example.com/', 'https://nowhere.example.com/'] as $url) {
            $this->actingAs($admin)->putJson('/api/billing/settings', ['webhook_url' => $url])->assertStatus(422)->assertJsonValidationErrors('webhook_url');
        }
        $this->assertNull(BillingSetting::query()->find('webhook_url'));

        $this->actingAs($admin)->putJson('/api/billing/settings', ['webhook_url' => 'https://hooks.example.com/runr'])->assertOk()
            ->assertJsonPath('webhook.url', 'https://hooks.example.com/runr');
    }

    public function test_every_delivery_checks_the_url_again(): void
    {
        $admin = $this->userWithRole('owner');
        Http::fake(['*' => Http::response('', 200)]);
        $this->actingAs($admin)->putJson('/api/billing/settings', ['webhook_url' => 'https://hooks.example.com/runr'])->assertOk();

        // The name resolves to an internal address now: nothing is sent, the attempt fails with the reason
        $this->dns['hooks.example.com'] = ['169.254.169.254'];
        $response = $this->actingAs($admin)->postJson('/api/billing/events/test')->assertOk()->assertJsonPath('delivered', false);

        Http::assertNothingSent();
        $this->assertSame(__('observability.url.private'), $response->json('data.delivery.last_error'));
    }

    public function test_the_operators_webhook_may_be_internal(): void
    {
        // BILLING_WEBHOOK_URL is the operator's: an invoicing service in the cluster is fine
        config(['billing.webhook.url' => 'http://invoicing.billing.svc.cluster.local/hooks/runr']);
        Http::fake(['*' => Http::response('', 204)]);

        $event = app(BillingEvents::class)->record(BillingEvents::TEST, []);

        $this->assertSame(BillingEvent::DELIVERED, $event->fresh()->delivery_status);
        Http::assertSentCount(1);
    }

    public function test_a_connection_error_is_kept_for_the_platforms_administrators(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to invoicing.example.com port 443'));

        $event = app(BillingEvents::class)->record(BillingEvents::TEST, []);

        $this->assertStringContainsString('cURL error 7', (string) $event->fresh()->last_error);
    }
}
