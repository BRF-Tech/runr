<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Services\AgentMessageHandler;
use App\Services\SessionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The region agent's heartbeat (runtime/agent session Tracker.heartbeat): every minute it lists
 * the sessions it still sees running. A listed session is marked seen (a stale-closed one
 * reopened, a missing one started); a session whose heartbeats stop is closed as of when it was
 * last seen, after HEARTBEAT_STALE_AFTER_MINUTES — however long its pod has run. Sessions that
 * never had a heartbeat (agents from before heartbeats) keep the 30-minute close.
 */
class AgentSessionHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const POD = 'orders-00001-deployment-6b8f9c7d4-xk9pq';

    private KnativeRegion $region;

    private Project $project;

    private Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-11 10:00:00');

        $this->region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);
        $this->project = $project->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow("2026-10-11 {$time}");
    }

    private function send(string $type, string $pod): void
    {
        app(AgentMessageHandler::class)->handle([
            'type' => $type, 'project_slug' => $this->project->slug, 'project_id' => $this->project->id,
            'entry_point' => 'http', 'pod' => $pod, 'cold_start' => true,
        ], $this->region->id);
    }

    /** @param list<string> $pods */
    private function heartbeat(array $pods, ?string $slug = null): void
    {
        app(AgentMessageHandler::class)->handle([
            'type' => 'session_heartbeat',
            'sessions' => array_map(fn (string $pod) => [
                'project_slug' => $slug ?? $this->project->slug, 'project_id' => $this->project->id, 'pod' => $pod, 'entry_point' => 'http',
            ], $pods),
        ], $this->region->id);
    }

    private function podSession(string $pod): DeploymentSession
    {
        return DeploymentSession::query()->where('metadata->pod', $pod)->sole();
    }

    private function closeStale(): int
    {
        return app(SessionTrackingService::class)->closeStale();
    }

    public function test_an_environment_pod_is_a_session_of_the_environment_deployment(): void
    {
        // staging runs in another region (docs/environments.md#another-region)
        $other = KnativeRegion::create(['name' => 'us', 'project_namespace' => 'us', 'is_active' => true]);
        $staging = ProjectEnvironment::query()->create([
            'project_id' => $this->project->id, 'name' => 'staging', 'is_primary' => false,
            'workload_name' => 'orders-staging', 'knative_region_id' => $other->id, 'position' => 1,
        ]);
        // applied in its region (KnativeDeployService::recordSizing)
        $deployment = Deployment::create(['project_id' => $this->project->id, 'version_id' => $this->deployment->version_id, 'status' => 'active', 'environment_id' => $staging->id]);
        $deployment->forceFill(['sizing' => ['region_id' => $other->id, 'cpu_m' => 100, 'memory_mb' => 128, 'trigger_type' => 'http']])->save();
        $pod = 'orders-staging-00001-deployment-6b8f9c7d4-xk9pq';
        $event = fn (string $type) => [
            'type' => $type, 'project_slug' => 'orders-staging', 'project_id' => $this->project->id,
            'environment_id' => $staging->id, 'environment' => 'staging', 'entry_point' => 'http', 'pod' => $pod,
        ];

        // reported by the agent of another region: nobody's
        app(AgentMessageHandler::class)->handle($event('session_start'), $this->region->id);
        $this->assertSame(0, DeploymentSession::query()->count());

        app(AgentMessageHandler::class)->handle($event('session_start'), $other->id);
        $session = $this->podSession($pod);
        $this->assertSame((string) $deployment->id, (string) $session->deployment_id);
        $this->assertSame((string) $other->id, (string) $session->region_id);

        $this->at('10:01:00');
        app(AgentMessageHandler::class)->handle(['type' => 'session_heartbeat', 'sessions' => [$event('x')]], $other->id);
        $this->assertSame('2026-10-11 10:01:00', $this->podSession($pod)->last_seen_at?->toDateTimeString());

        $this->at('10:05:00');
        app(AgentMessageHandler::class)->handle($event('session_end'), $other->id);
        $this->assertNotNull($this->podSession($pod)->ended_at);
    }

    public function test_a_heartbeat_marks_the_sessions_it_lists_seen(): void
    {
        $this->send('session_start', self::POD);
        $this->send('session_start', 'orders-00001-deployment-6b8f9c7d4-bcdfg');
        $this->assertNull($this->podSession(self::POD)->last_seen_at);

        $this->at('10:01:00');
        $this->heartbeat([self::POD]);

        $this->assertSame('2026-10-11 10:01:00', $this->podSession(self::POD)->last_seen_at?->toDateTimeString());
        $this->assertNull($this->podSession('orders-00001-deployment-6b8f9c7d4-bcdfg')->last_seen_at, 'not listed');
        $this->assertSame(2, DeploymentSession::query()->count());
    }

    public function test_a_session_whose_heartbeats_stop_is_closed_as_of_when_it_was_last_seen(): void
    {
        $this->send('session_start', self::POD);
        $this->at('10:01:00');
        $this->heartbeat([self::POD]);
        $this->at('10:45:00');
        $this->heartbeat([self::POD]);

        // Open for 50 minutes but seen 5 minutes ago: the pod still runs
        $this->at('10:50:00');
        $this->assertSame(0, $this->closeStale());
        $this->assertNull($this->podSession(self::POD)->ended_at);

        // No heartbeat for 11 minutes: the agent or the region is gone
        $this->at('10:56:00');
        $this->assertSame(1, $this->closeStale());

        $session = $this->podSession(self::POD);
        $this->assertSame('2026-10-11 10:45:00', $session->ended_at?->toDateTimeString(), 'ended when it was last seen');
        $this->assertSame(45 * 60_000, $session->duration_ms);
        $this->assertTrue($session->metadata[SessionTrackingService::STALE_CLOSED] ?? false);

        // The heartbeats come back (the agent reconnected): the session continues
        $this->at('11:10:00');
        $this->heartbeat([self::POD]);
        $session = $this->podSession(self::POD);
        $this->assertNull($session->ended_at);
        $this->assertNull($session->duration_ms);
        $this->assertArrayNotHasKey(SessionTrackingService::STALE_CLOSED, $session->metadata ?? []);
        $this->assertSame('2026-10-11 11:10:00', $session->last_seen_at?->toDateTimeString());

        // Its real end counts the whole session
        $this->at('11:30:00');
        $this->send('session_end', self::POD);
        $this->assertSame(90 * 60_000, $this->podSession(self::POD)->duration_ms);
        $this->assertSame(1, DeploymentSession::query()->count());
    }

    public function test_a_heartbeat_starts_the_session_of_a_pod_whose_start_was_lost(): void
    {
        $this->heartbeat([self::POD]);

        $session = $this->podSession(self::POD);
        $this->assertSame($this->deployment->id, $session->deployment_id);
        $this->assertSame('2026-10-11 10:00:00', $session->started_at->toDateTimeString());
        $this->assertSame('2026-10-11 10:00:00', $session->last_seen_at?->toDateTimeString());
        $this->assertSame('http', $session->entry_point);
        $this->assertFalse($session->cold_start, 'not known to be a cold start');

        // Further heartbeats and a late session_start continue it
        $this->at('10:01:00');
        $this->heartbeat([self::POD]);
        $this->send('session_start', self::POD);
        $this->assertSame(1, DeploymentSession::query()->count());
        $this->assertSame('2026-10-11 10:01:00', $this->podSession(self::POD)->last_seen_at?->toDateTimeString());

        $this->at('10:05:00');
        $this->send('session_end', self::POD);
        $this->assertSame(5 * 60_000, $this->podSession(self::POD)->duration_ms);
    }

    public function test_heartbeats_of_unknown_projects_and_malformed_entries_are_ignored(): void
    {
        $this->heartbeat([self::POD], slug: 'nowhere');
        app(AgentMessageHandler::class)->handle(['type' => 'session_heartbeat', 'sessions' => [
            'not-an-entry', ['project_slug' => $this->project->slug], ['pod' => self::POD], ['project_slug' => '', 'pod' => self::POD],
        ]], $this->region->id);
        app(AgentMessageHandler::class)->handle(['type' => 'session_heartbeat', 'sessions' => 'nope'], $this->region->id);
        app(AgentMessageHandler::class)->handle(['type' => 'session_heartbeat'], $this->region->id);

        // A project without an active deployment gets no session from a heartbeat
        $this->deployment->update(['status' => 'shut_down']);
        $this->heartbeat([self::POD]);

        $this->assertSame(0, DeploymentSession::query()->count());
    }

    public function test_sessions_without_heartbeats_keep_the_thirty_minute_close(): void
    {
        // An agent from before heartbeats reports this pod; a current one reports the other
        $this->send('session_start', self::POD);
        $this->send('session_start', 'orders-00001-deployment-6b8f9c7d4-bcdfg');
        foreach (['10:01:00', '10:12:00', '10:23:00', '10:34:00'] as $time) {
            $this->at($time);
            $this->heartbeat(['orders-00001-deployment-6b8f9c7d4-bcdfg']);
        }

        $this->at('10:35:00');
        $this->assertSame(1, $this->closeStale());

        $old = $this->podSession(self::POD);
        $this->assertSame('2026-10-11 10:35:00', $old->ended_at?->toDateTimeString(), 'ended at the close, as before');
        $this->assertTrue($old->metadata[SessionTrackingService::STALE_CLOSED] ?? false);
        $this->assertNull($this->podSession('orders-00001-deployment-6b8f9c7d4-bcdfg')->ended_at, 'seen a minute ago');
    }
}
