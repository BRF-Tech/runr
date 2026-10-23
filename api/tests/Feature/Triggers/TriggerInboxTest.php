<?php

namespace Tests\Feature\Triggers;

use App\Jobs\Triggers\NudgeEventGatewayJob;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\TriggerEvent;
use App\Services\KnativeDeployService;
use App\Triggers\Inbox\InboxToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * The event gateway's inbox (claim → deliver → ack) and the console / API viewer with replay
 * (docs/triggers.md#delivery).
 */
class TriggerInboxTest extends TestCase
{
    use ActsWithRoles;
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private Project $project;

    private ProjectTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NudgeEventGatewayJob::class]);
        $this->project = $this->triggerProject($this->triggerRegion(redis: false));
        $this->trigger = $this->addTrigger($this->project, 'webhook', ['verify' => 'none']);
    }

    private function event(array $attributes = []): TriggerEvent
    {
        return TriggerEvent::query()->create($attributes + [
            'project_id' => $this->project->id,
            'trigger_id' => $this->trigger->id,
            'kind' => TriggerEvent::KIND_WEBHOOK,
            'event_type' => 'dev.runr.webhook.received',
            'content_type' => 'application/json',
            'headers' => ['x-github-event' => 'push'],
            'body' => base64_encode('{"a":1}'),
            'body_size' => 7,
            'received_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function inboxUrl(string $action, ?string $token = null): string
    {
        return "/api/internal/triggers/central/{$this->project->id}/".($token ?? InboxToken::for('central', $this->project->id))."/{$action}";
    }

    public function test_a_wrong_token_is_not_found(): void
    {
        $this->postJson($this->inboxUrl('claim', str_repeat('0', 40)), ['trigger' => 'webhook'])->assertNotFound();
    }

    public function test_claim_leases_pending_events_once_and_ack_records_the_outcome(): void
    {
        $delivered = $this->event(['delivery_id' => 'd-1']);
        $failed = $this->event();

        $claim = $this->postJson($this->inboxUrl('claim'), ['trigger' => 'webhook', 'limit' => 10, 'lease_seconds' => 600])->assertOk();
        $events = collect($claim->json('events'));
        $this->assertSame([$delivered->id, $failed->id], $events->pluck('id')->all());
        $first = $events->first();
        $this->assertSame('dev.runr.webhook.received', $first['type']);
        $this->assertSame('/webhooks/webhook', $first['source']);
        $this->assertSame(base64_encode('{"a":1}'), $first['data_base64']);
        $this->assertSame('d-1', $first['extensions']['runrdelivery']);
        $this->assertSame(['x-github-event' => 'push'], json_decode($first['extensions']['runrheaders'], true));
        $this->assertSame(0, $first['attempts']);

        // leased: a second gateway (or poll) gets nothing
        $this->postJson($this->inboxUrl('claim'), ['trigger' => 'webhook'])->assertOk()->assertExactJson(['events' => []]);

        $this->postJson($this->inboxUrl('ack'), ['trigger' => 'webhook', 'results' => [
            ['id' => $delivered->id, 'status' => 'delivered'],
            ['id' => $failed->id, 'status' => 'failed', 'error' => 'POST http://fn: status 500'],
        ]])->assertOk()->assertJson(['acknowledged' => 2]);

        $this->assertSame(TriggerEvent::DELIVERED, $delivered->fresh()->status);
        $this->assertNotNull($delivered->fresh()->delivered_at);
        $this->assertSame(TriggerEvent::FAILED, $failed->fresh()->status);
        $this->assertSame('POST http://fn: status 500', $failed->fresh()->last_error);
    }

    public function test_an_expired_lease_is_offered_again_with_its_prior_attempts(): void
    {
        $event = $this->event(['status' => TriggerEvent::DELIVERING, 'lease_until' => now()->subMinute(), 'attempts' => 1]);

        $claimed = $this->postJson($this->inboxUrl('claim'), ['trigger' => 'webhook'])->assertOk()->json('events');

        $this->assertSame($event->id, $claimed[0]['id']);
        $this->assertSame(1, $claimed[0]['attempts']);
        $this->assertSame(2, $event->fresh()->attempts);
    }

    public function test_a_disabled_trigger_and_expired_events_are_not_claimed(): void
    {
        $this->event(['expires_at' => now()->subMinute()]);
        $this->postJson($this->inboxUrl('claim'), ['trigger' => 'webhook'])->assertOk()->assertExactJson(['events' => []]);

        $this->event();
        $this->trigger->forceFill(['enabled' => false])->save();
        $this->postJson($this->inboxUrl('claim'), ['trigger' => 'webhook'])->assertOk()->assertExactJson(['events' => []]);
    }

    public function test_an_ack_for_a_replayed_event_is_ignored(): void
    {
        $event = $this->event(['status' => TriggerEvent::PENDING]);

        $this->postJson($this->inboxUrl('ack'), ['trigger' => 'webhook', 'results' => [['id' => $event->id, 'status' => 'delivered']]])
            ->assertOk()->assertJson(['acknowledged' => 0]);
        $this->assertSame(TriggerEvent::PENDING, $event->fresh()->status);
    }

    public function test_the_viewer_lists_shows_and_replays_events(): void
    {
        $owner = $this->userWithRole('owner');
        $failed = $this->event(['status' => TriggerEvent::FAILED, 'attempts' => 6, 'last_error' => 'status 500']);
        $this->event(['status' => TriggerEvent::DELIVERED]);
        $base = "/api/projects/{$this->project->id}/triggers/webhook/events";

        $this->actingAs($owner, 'sanctum')->getJson($base.'?status=failed')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $failed->id);

        $this->actingAs($owner, 'sanctum')->getJson("{$base}/{$failed->id}")
            ->assertOk()->assertJsonPath('data.body_text', '{"a":1}')->assertJsonPath('data.headers.x-github-event', 'push');

        $this->actingAs($owner, 'sanctum')->postJson("{$base}/{$failed->id}/replay")
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.replays', 1);
        $this->assertSame(0, $failed->fresh()->attempts);
        Bus::assertDispatched(NudgeEventGatewayJob::class);

        // (from the row as the replay left it: the model in hand still says failed, a save would change nothing)
        $failed->fresh()->forceFill(['status' => TriggerEvent::FAILED])->save();
        $this->actingAs($owner, 'sanctum')->postJson("{$base}/replay", ['status' => 'failed'])->assertOk()->assertJson(['replayed' => 1]);
    }

    public function test_viewing_events_needs_replay_manage(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer, 'sanctum')->getJson("/api/projects/{$this->project->id}/triggers/webhook/events")->assertForbidden();
    }

    public function test_events_of_a_trigger_that_stores_none_are_not_found(): void
    {
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$this->project->id}/triggers/http/events")->assertNotFound();
    }

    public function test_prune_deletes_events_past_their_retention(): void
    {
        $old = $this->event(['expires_at' => now()->subDay()]);
        $kept = $this->event();

        $this->artisan('triggers:prune')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($kept->fresh());
    }

    public function test_the_nudge_posts_to_the_gateway_service_through_the_api_proxy(): void
    {
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request->method().' '.$request->url();

            return Http::response('{}', 200);
        });

        (new NudgeEventGatewayJob($this->project->id, 'webhook'))->handle(app(KnativeDeployService::class));

        $this->assertSame(["POST https://k8s.test/api/v1/namespaces/ev/services/http:{$this->project->slug}-qc:8081/proxy/v1/nudge?trigger=webhook"], $calls);
    }
}
