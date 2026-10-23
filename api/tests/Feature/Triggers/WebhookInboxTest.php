<?php

namespace Tests\Feature\Triggers;

use App\Jobs\Triggers\NudgeEventGatewayJob;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\TriggerEvent;
use App\Triggers\Types\WebhookTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * Webhook inbox (docs/triggers.md#webhook-inbox): POST /api/hooks/{context}/{key} verifies the
 * sender's signature with the trigger's preset, drops a delivery id it already stored, stores the
 * request and answers 200 — the gateway delivers afterwards.
 */
class WebhookInboxTest extends TestCase
{
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private const SECRET = 'whsec_test_signing_secret';

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NudgeEventGatewayJob::class]);
        $this->project = $this->triggerProject($this->triggerRegion(redis: false));
    }

    private function inbox(array $config, array $attributes = []): ProjectTrigger
    {
        return $this->addTrigger($this->project, 'webhook', $config, $attributes + ['secrets' => ['signing_secret' => self::SECRET]]);
    }

    private function send(ProjectTrigger $trigger, string $body, array $headers = []): TestResponse
    {
        return $this->call('POST', '/api/hooks/central/'.$trigger->ingress_key, [], [], [], $this->server($headers + ['Content-Type' => 'application/json']), $body);
    }

    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    public function test_an_unverified_inbox_stores_the_request_and_answers_200(): void
    {
        $trigger = $this->inbox(['verify' => 'none']);

        $response = $this->send($trigger, '{"order":17}', ['X-Custom' => 'yes', 'Authorization' => 'Bearer must-not-be-stored']);

        $response->assertOk()->assertJson(['status' => 'stored']);
        $event = TriggerEvent::query()->sole();
        $this->assertSame(TriggerEvent::PENDING, $event->status);
        $this->assertSame(WebhookTrigger::EVENT_TYPE, $event->event_type);
        $this->assertSame('{"order":17}', $event->rawBody());
        $this->assertSame('yes', $event->headers['x-custom']);
        $this->assertArrayNotHasKey('authorization', $event->headers);
        $this->assertNotNull($event->expires_at);
        Bus::assertDispatched(NudgeEventGatewayJob::class, fn ($job) => $job->projectId === $this->project->id && $job->trigger === 'webhook');
    }

    public function test_put_reaches_the_same_inbox(): void
    {
        $trigger = $this->inbox(['verify' => 'none']);

        $this->call('PUT', '/api/hooks/central/'.$trigger->ingress_key, [], [], [], $this->server(['Content-Type' => 'text/plain']), 'put body')
            ->assertOk()->assertJson(['status' => 'stored']);
        $this->assertSame('put body', TriggerEvent::query()->sole()->rawBody());

        $this->call('PUT', '/api/hooks/central/'.str_repeat('0', 40), [], [], [], $this->server([]), 'x')->assertNotFound();
        $this->call('PATCH', '/api/hooks/central/'.$trigger->ingress_key, [], [], [], $this->server([]), 'x')->assertStatus(405);
    }

    public function test_github_signatures_and_delivery_ids(): void
    {
        $trigger = $this->inbox(['verify' => 'github']);
        $body = '{"action":"opened"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

        $this->send($trigger, $body, ['X-Hub-Signature-256' => 'sha256=deadbeef', 'X-GitHub-Delivery' => 'd-1'])->assertStatus(401);
        $this->send($trigger, $body, ['X-GitHub-Delivery' => 'd-1'])->assertStatus(401);

        $first = $this->send($trigger, $body, ['X-Hub-Signature-256' => $signature, 'X-GitHub-Delivery' => 'd-1', 'X-GitHub-Event' => 'pull_request']);
        $first->assertOk()->assertJson(['status' => 'stored']);
        $again = $this->send($trigger, $body, ['X-Hub-Signature-256' => $signature, 'X-GitHub-Delivery' => 'd-1', 'X-GitHub-Event' => 'pull_request']);
        $again->assertOk()->assertJson(['status' => 'duplicate', 'id' => $first->json('id')]);

        $event = TriggerEvent::query()->sole();
        $this->assertSame('d-1', $event->delivery_id);
        $this->assertSame('pull_request', $event->subject);
    }

    public function test_stripe_signatures_within_the_tolerance(): void
    {
        $trigger = $this->inbox(['verify' => 'stripe']);
        $body = '{"id":"evt_1","type":"invoice.paid"}';
        $sign = fn (int $t) => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::SECRET);

        $this->send($trigger, $body, ['Stripe-Signature' => $sign(time() - 3600)])->assertStatus(401);
        $this->send($trigger, $body, ['Stripe-Signature' => $sign(time())])->assertOk();

        $event = TriggerEvent::query()->sole();
        $this->assertSame('evt_1', $event->delivery_id);
        $this->assertSame('invoice.paid', $event->subject);
    }

    public function test_slack_signatures_and_the_url_verification_handshake(): void
    {
        $trigger = $this->inbox(['verify' => 'slack']);
        $ts = (string) time();
        $sign = fn (string $body) => 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", self::SECRET);

        $handshake = '{"type":"url_verification","challenge":"c-123"}';
        $this->send($trigger, $handshake, ['X-Slack-Signature' => $sign($handshake), 'X-Slack-Request-Timestamp' => $ts])
            ->assertOk()->assertExactJson(['challenge' => 'c-123']);
        $this->assertSame(0, TriggerEvent::query()->count());

        $event = '{"type":"event_callback","event_id":"Ev1","event":{"type":"app_mention"}}';
        $this->send($trigger, $event, ['X-Slack-Signature' => $sign($event), 'X-Slack-Request-Timestamp' => $ts])->assertOk();
        $this->assertSame(['Ev1', 'app_mention'], [TriggerEvent::query()->sole()->delivery_id, TriggerEvent::query()->sole()->subject]);
    }

    public function test_generic_hmac_with_header_prefix_and_base64(): void
    {
        $trigger = $this->inbox([
            'verify' => 'hmac',
            'hmac' => ['header' => 'X-Acme-Signature', 'algorithm' => 'sha512', 'encoding' => 'base64', 'prefix' => 'sig='],
            'delivery_id_header' => 'X-Acme-Delivery',
        ]);
        $body = 'plain text body';
        $signature = 'sig='.base64_encode(hash_hmac('sha512', $body, self::SECRET, true));

        $this->send($trigger, $body, ['X-Acme-Signature' => base64_encode(hash_hmac('sha512', $body, self::SECRET, true))])->assertStatus(401);
        $this->send($trigger, $body, ['X-Acme-Signature' => $signature, 'X-Acme-Delivery' => 'a-9', 'Content-Type' => 'text/plain'])->assertOk();

        $this->assertSame('a-9', TriggerEvent::query()->sole()->delivery_id);
        $this->assertSame('text/plain', TriggerEvent::query()->sole()->content_type);
    }

    public function test_unknown_disabled_stopped_and_too_large(): void
    {
        $trigger = $this->inbox(['verify' => 'none', 'max_body_kb' => 1]);

        $this->call('POST', '/api/hooks/central/'.str_repeat('a', 40))->assertNotFound();
        $this->send($trigger, str_repeat('x', 2048))->assertStatus(413);

        $this->project->forceFill(['stopped_at' => now()])->saveQuietly();
        $this->send($trigger, '{}')->assertStatus(409);

        $this->project->forceFill(['stopped_at' => null])->saveQuietly();
        $trigger->forceFill(['enabled' => false])->save();
        $this->send($trigger, '{}')->assertNotFound();

        $this->assertSame(0, TriggerEvent::query()->count());
    }

    public function test_the_inbox_url_is_stable_and_shown_without_the_secret(): void
    {
        $trigger = $this->inbox(['verify' => 'github']);
        $key = $trigger->ingress_key;
        $this->assertMatchesRegularExpression('/^[a-z0-9]{40}$/', $key);

        $presented = $trigger->handler()->present($trigger);
        $this->assertStringEndsWith('/api/hooks/central/'.$key, $presented['url']);
        $this->assertTrue($presented['has_signing_secret']);

        $trigger->forceFill(['config' => ['verify' => 'none']])->save();
        $this->assertSame($key, $trigger->fresh()->ingress_key);
    }
}
