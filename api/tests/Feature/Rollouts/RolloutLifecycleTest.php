<?php

namespace Tests\Feature\Rollouts;

use App\Events\DeploymentCreated;
use App\Events\PlatformEvent;
use App\Jobs\AdvanceRolloutJob;
use App\Jobs\AdvanceRolloutsJob;
use App\Jobs\CheckDeploymentReadyJob;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Services\Projects\LiveDeployments;
use App\Services\Rollouts\RolloutMetrics;
use App\Services\Rollouts\RolloutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRolloutCluster;
use Tests\TestCase;

/**
 * Blue-green and canary rollouts end to end against a fake region cluster (docs/deploy-strategies.md,
 * "Deploy strategies"): the deploy keeps what serves and pins the traffic, the ready revision
 * waits for its promotion or walks its canary steps, promote / abort / rollback move the traffic
 * and swap the deployments, the previous revision is kept warm and released, thresholds and
 * health checks decide by themselves, and every way a deployment ends outside of the rollout
 * ends the rollout too.
 */
class RolloutLifecycleTest extends TestCase
{
    use ActsWithRoles, FakesRolloutCluster, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
        // The deploy job and the readiness / rollout checks are run by hand in each test
        Event::fake([DeploymentCreated::class]);
        Bus::fake([CheckDeploymentReadyJob::class, AdvanceRolloutJob::class]);
        Carbon::setTestNow('2026-10-21 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A project served by v1 (revision shop-00001) and the deploy of v2 with $strategy: the API
     * answer, the new deployment and its applied revision name.
     *
     * @return array{0: Project, 1: Deployment, 2: Deployment, 3: string, 4: Version}
     */
    private function rollingOut(array $strategy, string $name = 'shop'): array
    {
        $project = $this->rolloutProject($name, 'http', $strategy);
        $v1 = $this->rolloutVersion($project, 1);
        $v2 = $this->rolloutVersion($project, 2);
        $stable = $this->rolloutDeployment($project, $v1, 'active');
        $this->servedBy("{$name}-00001");
        $this->fakeRolloutCluster();

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v2->id])
            ->assertAccepted();
        $candidate = Deployment::query()->findOrFail($response->json('id'));

        app(KnativeDeployService::class)->deploy($project->fresh(), $v2, $candidate);
        $revision = "{$name}-v2-".substr(strtolower($candidate->id), -6);

        return [$project->fresh(), $stable, $candidate, $revision, $v2];
    }

    /** The rollout's new revision becomes ready (the readiness check sees it). */
    private function ready(Project $project, Deployment $candidate, string $revision): void
    {
        $this->revisionReady($revision);
        (new CheckDeploymentReadyJob($candidate->fresh(), $project))->handle();
    }

    private function advance(): void
    {
        app(RolloutService::class)->advance(DeploymentRollout::query()->sole());
    }

    private function rollout(): DeploymentRollout
    {
        return DeploymentRollout::query()->sole();
    }

    // ── Blue-green ─────────────────────────────────────────────────────────────

    public function test_a_blue_green_deploy_keeps_what_serves_and_pins_the_traffic(): void
    {
        $project = $this->rolloutProject('shop', 'http', ['strategy' => 'blue_green']);
        $v1 = $this->rolloutVersion($project, 1);
        $v2 = $this->rolloutVersion($project, 2);
        $stable = $this->rolloutDeployment($project, $v1, 'active');
        $this->servedBy('shop-00001');
        $this->fakeRolloutCluster();

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v2->id])
            ->assertAccepted()
            ->assertJsonPath('rollout.strategy', 'blue_green')
            ->assertJsonPath('rollout.status', 'pending')
            ->assertJsonPath('rollout.traffic', ['candidate' => 0, 'stable' => 100])
            ->assertJsonPath('rollout_role', 'candidate');
        $candidate = Deployment::query()->findOrFail($response->json('id'));

        $this->assertSame('active', $stable->fresh()->status, 'what serves stays live while the new one rolls out');

        app(KnativeDeployService::class)->deploy($project->fresh(), $v2, $candidate);

        $revision = 'shop-v2-'.substr(strtolower($candidate->id), -6);
        $applied = $this->appliedService();
        $this->assertSame($revision, $applied['spec']['template']['metadata']['name']);
        $this->assertSame([
            ['revisionName' => 'shop-00001', 'percent' => 100],
            ['revisionName' => $revision, 'percent' => 0, 'tag' => 'green'],
        ], $applied['spec']['traffic']);
        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertSame(['shop-00001', $revision], [$this->rollout()->stable_revision, $this->rollout()->revision]);
    }

    public function test_a_ready_blue_green_revision_waits_for_its_promotion_then_takes_all_traffic(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green', 'blue_green' => ['keep_previous_minutes' => 15]]);

        $this->ready($project, $candidate, $revision);

        $this->assertSame('running', $candidate->fresh()->status, 'ready next to the active one');
        $this->assertSame('active', $stable->fresh()->status);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/rollout")
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_promotion')
            ->assertJsonPath('data.test_url', 'https://green-shop.fn.example.com')
            ->assertJsonPath('data.can', ['promote' => true, 'abort' => true, 'rollback' => false]);
        $this->assertSame([], $this->trafficPatches(), 'nothing moves before the promotion');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/promote")
            ->assertOk()
            ->assertJsonPath('data.status', 'promoted')
            ->assertJsonPath('data.traffic', ['candidate' => 100, 'stable' => 0])
            ->assertJsonPath('data.previous_url', 'https://previous-shop.fn.example.com')
            ->assertJsonPath('data.can.rollback', true);

        $this->assertSame([
            ['revisionName' => $revision, 'percent' => 100, 'tag' => 'green'],
            ['revisionName' => 'shop-00001', 'percent' => 0, 'tag' => 'previous'],
        ], $this->lastTraffic());
        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertSame('running', $stable->fresh()->status, 'the previous one is kept warm');
        $this->assertSame([$candidate->id], Deployment::query()->where('status', 'active')->pluck('id')->all(), 'one active deployment');
        $this->assertTrue(collect($this->k8sCalls)->contains(fn ($c) => $c['method'] === 'PATCH'
            && str_ends_with($c['path'], '/podautoscalers/shop-00001')
            && ($c['body']['metadata']['annotations']['autoscaling.knative.dev/min-scale'] ?? null) === '1'), 'the previous revision is kept warm');
        $this->assertSame('2026-10-21 10:15:00', $this->rollout()->keep_until?->toDateTimeString());
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.promote')->exists());

        // The keep window is over: Knative's default traffic, the previous deployment goes
        Carbon::setTestNow('2026-10-21 10:15:01');
        $this->advance();

        $this->assertSame([['latestRevision' => true, 'percent' => 100]], $this->lastTraffic());
        $this->assertNotNull($this->rollout()->released_at);
        $this->assertFalse($stable->fresh()->isLive());
        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.released')->where('actor_type', 'system')->exists());
    }

    public function test_a_rollback_after_the_promotion_switches_back_to_the_warm_previous_revision(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green']);
        $this->ready($project, $candidate, $revision);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/promote")->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/rollback", ['reason' => 'checkout broken'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rolled_back')
            ->assertJsonPath('data.reason', 'checkout broken')
            ->assertJsonPath('data.can', ['promote' => false, 'abort' => false, 'rollback' => false]);

        $this->assertSame([['revisionName' => 'shop-00001', 'percent' => 100]], $this->lastTraffic());
        $this->assertSame('active', $stable->fresh()->status);
        $this->assertFalse($candidate->fresh()->isLive());
        $this->assertSame('checkout broken', $candidate->fresh()->knative_message);
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.rollback')->exists());

        // Nothing to roll back to any more
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/rollback")
            ->assertStatus(409)
            ->assertJsonPath('code', 'rollout_not_rollbackable');
    }

    public function test_an_abort_drops_the_new_revision_and_the_traffic_stays(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green']);
        $this->ready($project, $candidate, $revision);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/abort")
            ->assertOk()
            ->assertJsonPath('data.status', 'aborted')
            ->assertJsonPath('data.test_url', null);

        $this->assertSame([['revisionName' => 'shop-00001', 'percent' => 100]], $this->lastTraffic());
        $this->assertSame('active', $stable->fresh()->status);
        $this->assertFalse($candidate->fresh()->isLive());
        $this->assertStringContainsString($this->owner->username, (string) $this->rollout()->reason);
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.abort')->exists());

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/abort")
            ->assertStatus(409)
            ->assertJsonPath('code', 'rollout_not_abortable');
    }

    public function test_a_rollout_whose_revision_is_not_ready_cannot_be_promoted(): void
    {
        [$project, , $candidate] = $this->rollingOut(['strategy' => 'blue_green']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/promote")
            ->assertStatus(409)
            ->assertJsonPath('code', 'rollout_not_promotable');
        $this->assertSame('pending', $this->rollout()->status);
    }

    public function test_blue_green_promotes_itself_after_consecutive_healthy_checks(): void
    {
        $this->healthStatus = [503, 200, 200];
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green', 'blue_green' => [
            'auto_promote' => true, 'health_path' => '/healthz', 'health_checks' => 2, 'keep_previous_minutes' => 0,
        ]]);
        $this->ready($project, $candidate, $revision);
        $this->assertSame('progressing', $this->rollout()->status);

        $this->advance();
        $this->assertSame(0, $this->rollout()->health['passes'], 'a failed check starts the count again');
        $this->assertSame(503, $this->rollout()->health['last_status']);

        Carbon::setTestNow(now()->addSeconds(10));
        $this->advance();
        $this->assertSame(1, $this->rollout()->health['passes']);

        Carbon::setTestNow(now()->addSeconds(10));
        $this->advance();

        $this->assertSame('promoted', $this->rollout()->status);
        $this->assertNotNull($this->rollout()->released_at, 'nothing kept: released with the promotion');
        $this->assertSame([['latestRevision' => true, 'percent' => 100]], $this->lastTraffic());
        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertFalse($stable->fresh()->isLive());
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.auto_promoted')->where('actor_type', 'system')->exists());

        // The checks went to the new revision through the activator
        $check = collect($this->k8sCalls)->first(fn ($c) => str_contains($c['path'], 'activator-service'));
        $this->assertSame('/api/v1/namespaces/knative-serving/services/http:activator-service:80/proxy/healthz', $check['path']);
    }

    public function test_blue_green_health_checks_that_never_pass_fail_the_rollout(): void
    {
        $this->healthStatus = 500;
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green', 'blue_green' => [
            'auto_promote' => true, 'health_path' => '/healthz', 'health_checks' => 3,
        ]]);
        $this->ready($project, $candidate, $revision);

        $this->advance();
        Carbon::setTestNow(now()->addMinutes(6));
        $this->advance();

        $this->assertSame('failed', $this->rollout()->status);
        $this->assertStringContainsString('/healthz', (string) $this->rollout()->reason);
        $this->assertSame([['revisionName' => 'shop-00001', 'percent' => 100]], $this->lastTraffic());
        $this->assertSame('active', $stable->fresh()->status);
        $this->assertFalse($candidate->fresh()->isLive());
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.failed')->exists());
    }

    public function test_blue_green_without_a_health_path_promotes_itself_once_ready(): void
    {
        [$project, , $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green', 'blue_green' => ['auto_promote' => true]]);
        $this->ready($project, $candidate, $revision);

        $this->advance();

        $this->assertSame('promoted', $this->rollout()->status);
        $this->assertSame('active', $candidate->fresh()->status);
    }

    // ── Canary ─────────────────────────────────────────────────────────────────

    public function test_a_canary_walks_its_steps_and_is_promoted_at_100_percent(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'canary', 'canary' => [
            'steps' => [10, 50, 100], 'interval_minutes' => 5,
        ]]);
        $this->ready($project, $candidate, $revision);

        $this->assertSame([
            ['revisionName' => 'shop-00001', 'percent' => 90],
            ['revisionName' => $revision, 'percent' => 10, 'tag' => 'canary'],
        ], $this->lastTraffic());
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout")
            ->assertOk()
            ->assertJsonPath('data.status', 'progressing')
            ->assertJsonPath('data.step.index', 0)
            ->assertJsonPath('data.step.percent', 10)
            ->assertJsonPath('data.step.next_percent', 50)
            ->assertJsonPath('data.step.ends_at', '2026-10-21T10:05:00+00:00')
            ->assertJsonPath('data.metrics.tracing', false)
            ->assertJsonPath('data.test_url', 'https://canary-shop.fn.example.com');

        // Within the step: measured (no tracing: on time only), the share stays
        Carbon::setTestNow('2026-10-21 10:01:00');
        $this->advance();
        $this->assertSame(10, $this->rollout()->traffic_percent);

        Carbon::setTestNow('2026-10-21 10:05:00');
        $this->advance();
        $this->assertSame(50, $this->rollout()->traffic_percent);
        $this->assertSame(1, $this->rollout()->step_index);
        $this->assertSame(50, $this->lastTraffic()[1]['percent']);

        Carbon::setTestNow('2026-10-21 10:10:00');
        $this->advance();

        $this->assertSame('promoted', $this->rollout()->status);
        $this->assertSame([['latestRevision' => true, 'percent' => 100]], $this->lastTraffic());
        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertFalse($stable->fresh()->isLive(), 'a canary keeps no previous revision');
    }

    public function test_a_canary_step_above_its_error_rate_rolls_back_by_itself(): void
    {
        $asked = null;
        $this->mock(RolloutMetrics::class, function ($mock) use (&$asked) {
            // the new revision's own metrics are asked for (RolloutMetrics)
            $mock->shouldReceive('measure')->andReturnUsing(function ($project, int $window, ?string $revision) use (&$asked) {
                $asked = $revision;

                return [
                    'tracing' => true, 'source' => RolloutMetrics::SOURCE_SPANS, 'revision' => $revision, 'error_rate' => 12.5, 'p95_ms' => 80.0,
                    'volume' => 240.0, 'window_minutes' => 1, 'measured_at' => now()->toIso8601String(),
                ];
            });
        });
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'canary', 'canary' => [
            'steps' => [10, 100], 'interval_minutes' => 5, 'error_rate_threshold' => 5,
        ]]);
        $this->ready($project, $candidate, $revision);

        Carbon::setTestNow('2026-10-21 10:01:00');
        $this->advance();

        $rollout = $this->rollout();
        $this->assertSame($revision, $asked);
        $this->assertSame('rolled_back', $rollout->status);
        $this->assertStringContainsString('12.5', (string) $rollout->reason);
        $this->assertStringContainsString('new revision', (string) $rollout->reason);
        $this->assertSame([['revisionName' => 'shop-00001', 'percent' => 100]], $this->lastTraffic());
        $this->assertSame('active', $stable->fresh()->status);
        $this->assertFalse($candidate->fresh()->isLive());
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.auto_rolled_back')->where('actor_type', 'system')->exists());
    }

    public function test_too_few_requests_do_not_judge_a_canary_step(): void
    {
        $this->mock(RolloutMetrics::class, function ($mock) {
            $mock->shouldReceive('measure')->andReturn([
                'tracing' => true, 'error_rate' => 50.0, 'p95_ms' => 9000.0, 'volume' => 3.0,
                'window_minutes' => 1, 'measured_at' => now()->toIso8601String(),
            ]);
        });
        [$project, , $candidate, $revision] = $this->rollingOut(['strategy' => 'canary', 'canary' => ['steps' => [10, 100], 'p95_threshold_ms' => 500]]);
        $this->ready($project, $candidate, $revision);

        Carbon::setTestNow('2026-10-21 10:01:00');
        $this->advance();

        $this->assertSame('progressing', $this->rollout()->status);
        // stored as JSON: 50.0 reads back as 50
        $this->assertEquals(50.0, $this->rollout()->metrics['error_rate']);
    }

    public function test_promote_now_skips_the_remaining_canary_steps(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'canary']);
        $this->ready($project, $candidate, $revision);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/promote")
            ->assertOk()
            ->assertJsonPath('data.status', 'promoted');

        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertFalse($stable->fresh()->isLive());
    }

    // ── Deployments that end, newer deploys ──────────────────────────────────────

    public function test_a_new_revision_that_never_gets_ready_fails_the_rollout_and_the_traffic_stays(): void
    {
        [$project, $stable, $candidate] = $this->rollingOut(['strategy' => 'canary']);
        $this->ksvc['metadata']['generation'] = 2;
        $this->ksvc['status']['observedGeneration'] = 2;
        $this->ksvc['status']['conditions'] = [['type' => 'Ready', 'status' => 'False', 'reason' => 'RevisionFailed', 'message' => 'Container failed']];

        (new CheckDeploymentReadyJob($candidate->fresh(), $project, CheckDeploymentReadyJob::maxAttempts()))->handle();

        $this->assertSame('failed', $candidate->fresh()->status);
        $this->assertSame('failed', $this->rollout()->status);
        $this->assertSame([['revisionName' => 'shop-00001', 'percent' => 100]], $this->lastTraffic(), 'the new revision leaves the split');
        $this->assertSame('active', $stable->fresh()->status);
    }

    public function test_a_newer_deploy_replaces_an_open_rollout_and_keeps_what_serves(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'canary']);
        $this->ready($project, $candidate, $revision);
        $v3 = $this->rolloutVersion($project, 3);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v3->id])
            ->assertAccepted();

        $first = DeploymentRollout::query()->where('deployment_id', $candidate->id)->sole();
        $this->assertSame('aborted', $first->status);
        $this->assertSame([['revisionName' => 'shop-00001', 'percent' => 100]], $this->lastTraffic(), 'the canary share goes back first');
        $this->assertFalse($candidate->fresh()->isLive());
        $this->assertSame('active', $stable->fresh()->status);

        $second = DeploymentRollout::query()->where('deployment_id', $response->json('id'))->sole();
        $this->assertSame('pending', $second->status);
        $this->assertSame($stable->id, $second->stable_deployment_id);
    }

    public function test_the_deployment_list_shows_each_deployments_part_in_the_rollout(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green']);
        $this->ready($project, $candidate, $revision);

        $list = collect($this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/deployments")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('candidate', $list[$candidate->id]['rollout_role']);
        $this->assertSame('waiting_promotion', $list[$candidate->id]['rollout']['status']);
        $this->assertSame('stable', $list[$stable->id]['rollout_role']);
        $this->assertNull($list[$stable->id]['rollout']);
    }

    public function test_a_project_stop_ends_its_rollout(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'canary']);
        $this->ready($project, $candidate, $revision);

        // What a stop does to the deployments: both retired (EndRolloutWithDeployment hears it)
        app(LiveDeployments::class)->retire($candidate->fresh());
        app(LiveDeployments::class)->retire($stable->fresh());

        $this->assertContains($this->rollout()->status, ['aborted', 'failed']);
        $this->assertFalse($this->rollout()->holdsTraffic());
    }

    public function test_the_deployment_that_serves_cannot_be_deleted_during_a_rollout(): void
    {
        [$project, $stable, $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green']);
        $this->ready($project, $candidate, $revision);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/projects/{$project->id}/deployments/{$stable->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'rollout_in_progress');
        $this->assertSame('active', $stable->fresh()->status);

        // Deleting the new one aborts its rollout first
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/projects/{$project->id}/deployments/{$candidate->id}")
            ->assertNoContent();
        $this->assertSame('aborted', $this->rollout()->status);
        $this->assertSame([['revisionName' => 'shop-00001', 'percent' => 100]], $this->lastTraffic());
        $this->assertSame('active', $stable->fresh()->status);
    }

    public function test_rollout_changes_are_broadcast(): void
    {
        Event::fake([DeploymentCreated::class, PlatformEvent::class]);
        [$project, , $candidate, $revision] = $this->rollingOut(['strategy' => 'blue_green']);
        $this->ready($project, $candidate, $revision);

        Event::assertDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'deployment.rollout'
            && $e->payload['deployment_id'] === $candidate->id
            && $e->payload['status'] === 'running'
            && $e->payload['rollout']['status'] === 'waiting_promotion');
        Event::assertDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'deployment.status' && $e->payload['status'] === 'active');
    }

    // ── Direct deploys ───────────────────────────────────────────────────────────

    public function test_deploys_without_something_that_serves_or_with_strategy_direct_are_direct(): void
    {
        $project = $this->rolloutProject('first', 'http', ['strategy' => 'blue_green']);
        $v1 = $this->rolloutVersion($project, 1);
        $this->fakeRolloutCluster();

        // The first deploy: nothing serves yet
        $first = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id])->assertAccepted();
        $this->assertNull($first->json('rollout'));

        // strategy direct for one deploy: the active one is replaced at once
        Deployment::query()->whereKey($first->json('id'))->update(['status' => 'active', 'knative_ready' => true]);
        $direct = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id, 'strategy' => 'direct'])
            ->assertAccepted();
        $this->assertNull($direct->json('rollout'));
        $this->assertFalse(Deployment::query()->findOrFail($first->json('id'))->isLive());
        $this->assertSame(0, DeploymentRollout::query()->count());

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id, 'strategy' => 'rolling'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('strategy');
    }

    public function test_queue_functions_deploy_directly_whatever_is_stored(): void
    {
        $project = $this->rolloutProject('worker', 'queue', ['strategy' => 'canary']);
        $v1 = $this->rolloutVersion($project, 1);
        $this->rolloutDeployment($project, $v1, 'active');
        $this->fakeRolloutCluster();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id])
            ->assertAccepted()
            ->assertJsonPath('rollout', null);
    }

    public function test_a_direct_deploy_after_a_rollout_takes_the_traffic_back_for_the_latest_revision(): void
    {
        [$project, , $candidate, $revision, $v2] = $this->rollingOut(['strategy' => 'blue_green']);
        $this->ready($project, $candidate, $revision);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/abort")->assertOk();

        $direct = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v2->id, 'strategy' => 'direct'])
            ->assertAccepted();
        app(KnativeDeployService::class)->deploy($project->fresh(), $v2, Deployment::query()->findOrFail($direct->json('id')));

        $applied = $this->appliedService();
        $this->assertSame([['latestRevision' => true, 'percent' => 100]], $applied['spec']['traffic'], 'the pin the abort left goes');
        $this->assertArrayNotHasKey('name', $applied['spec']['template']['metadata']);
    }

    public function test_a_service_that_serves_nothing_turns_the_rollout_into_a_direct_deploy(): void
    {
        $project = $this->rolloutProject('moved', 'http', ['strategy' => 'blue_green']);
        $v1 = $this->rolloutVersion($project, 1);
        $stable = $this->rolloutDeployment($project, $v1, 'active');
        $this->ksvc = null; // e.g. the region moved to another cluster
        $this->fakeRolloutCluster();

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id])->assertAccepted();
        app(KnativeDeployService::class)->deploy($project->fresh(), $v1, Deployment::query()->findOrFail($response->json('id')));

        $this->assertSame(0, DeploymentRollout::query()->count());
        $this->assertFalse($stable->fresh()->isLive());
        $this->assertArrayNotHasKey('traffic', $this->appliedService('moved')['spec']);
    }

    // ── Timers ───────────────────────────────────────────────────────────────────

    public function test_a_check_queued_for_an_older_time_does_nothing(): void
    {
        [$project, , $candidate, $revision] = $this->rollingOut(['strategy' => 'canary']);
        $this->ready($project, $candidate, $revision);
        $rollout = $this->rollout();

        Carbon::setTestNow('2026-10-21 10:06:00');
        (new AdvanceRolloutJob($rollout->id, $rollout->next_check_at->getTimestamp() - 60))->handle(app(RolloutService::class));
        $this->assertSame(10, $this->rollout()->traffic_percent, 'a stale job leaves the step alone');

        (new AdvanceRolloutJob($rollout->id, $rollout->next_check_at->getTimestamp()))->handle(app(RolloutService::class));
        $this->assertSame(50, $this->rollout()->traffic_percent);
        Bus::assertDispatched(AdvanceRolloutJob::class);
    }

    public function test_the_minute_safety_net_moves_a_rollout_whose_check_was_lost(): void
    {
        [$project, , $candidate, $revision] = $this->rollingOut(['strategy' => 'canary']);
        $this->ready($project, $candidate, $revision);

        Carbon::setTestNow('2026-10-21 10:06:00');
        (new AdvanceRolloutsJob)->handle(app(RolloutService::class));

        $this->assertSame(50, $this->rollout()->traffic_percent);
    }
}
