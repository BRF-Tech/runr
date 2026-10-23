<?php

namespace Tests\Feature\Rollouts;

use App\Events\DeploymentCreated;
use App\Jobs\AdvanceRolloutJob;
use App\Jobs\CheckDeploymentReadyJob;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentManager;
use App\Services\Environments\Promotions;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRolloutCluster;
use Tests\TestCase;

/**
 * Deploy strategies per environment (docs/deploy-strategies.md#environments): an environment rolls
 * out with the project's settings unless it sets its own; its rollouts move the traffic of its own
 * Knative Service <slug>-<name>, promote, abort and roll back there, and never touch production's
 * deployments; a promotion into an environment follows the target's strategy.
 */
class EnvironmentRolloutTest extends TestCase
{
    use ActsWithRoles, FakesRolloutCluster, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
        Event::fake([DeploymentCreated::class]);
        Bus::fake([CheckDeploymentReadyJob::class, AdvanceRolloutJob::class]);
        Carbon::setTestNow('2026-10-22 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_environment_has_the_project_s_strategy_unless_it_sets_its_own(): void
    {
        $project = $this->rolloutProject('shop', 'http', ['strategy' => 'blue_green', 'blue_green' => ['keep_previous_minutes' => 30]]);
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging']);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/deploy-strategy?environment=staging")
            ->assertOk()
            ->assertJsonPath('data.environment', 'staging')
            ->assertJsonPath('data.strategy', 'blue_green')
            ->assertJsonPath('data.blue_green.keep_previous_minutes', 30)
            ->assertJsonPath('data.inherited', true);

        // Its own: canary with a step list of its own; what it does not set is the project's
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", [
            'environment' => 'staging', 'strategy' => 'canary', 'canary' => ['steps' => [25, 100]],
        ])
            ->assertOk()
            ->assertJsonPath('data.strategy', 'canary')
            ->assertJsonPath('data.canary.steps', [25, 100])
            ->assertJsonPath('data.canary.interval_minutes', 5)
            ->assertJsonPath('data.inherited', false)
            ->assertJsonPath('data.own.strategy', 'canary')
            ->assertJsonPath('data.project', 'blue_green');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/deploy-strategy")
            ->assertJsonPath('data.strategy', 'blue_green');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/environments/staging")
            ->assertJsonPath('data.deploy_strategy', 'canary');

        // Changing the environment's other overrides keeps its strategy
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/projects/{$project->id}/environments/staging", ['overrides' => ['min_scale' => 1]])->assertOk();
        $this->assertSame('canary', $staging->fresh()->overrideValues()['deploy_strategy']['strategy'] ?? null);

        // Validated like the project's
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", [
            'environment' => 'staging', 'canary' => ['steps' => [50, 20]],
        ])->assertStatus(422);

        // Back to the project's
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", ['environment' => 'staging', 'reset' => true])
            ->assertOk()
            ->assertJsonPath('data.strategy', 'blue_green')
            ->assertJsonPath('data.inherited', true);
    }

    public function test_an_environment_s_rollout_moves_its_own_traffic_and_leaves_production_alone(): void
    {
        [$project, $staging, $production, $stable, $candidate, $revision] = $this->rollingOutStaging(['strategy' => 'blue_green', 'blue_green' => ['keep_previous_minutes' => 15]]);

        $rollout = DeploymentRollout::query()->sole();
        $this->assertSame([$staging->id, 'shop-staging-00001'], [$rollout->environment_id, $rollout->stable_revision]);
        $this->assertStringStartsWith('shop-staging-v2-', $revision);
        $this->assertSame([
            ['revisionName' => 'shop-staging-00001', 'percent' => 100],
            ['revisionName' => $revision, 'percent' => 0, 'tag' => 'green'],
        ], $this->appliedService('shop-staging')['spec']['traffic']);
        $this->assertSame('active', $stable->fresh()->status, 'what serves staging stays live');

        // Ready next to it; the rollout of staging, not production's
        $this->revisionReady($revision);
        (new CheckDeploymentReadyJob($candidate->fresh(), DeployTarget::project($project, $candidate->fresh())))->handle();
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/rollout?environment=staging")
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_promotion')
            ->assertJsonPath('data.environment', 'staging')
            ->assertJsonPath('data.test_url', 'https://green-shop-staging.fn.example.com');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/rollout")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/promote")
            ->assertOk()
            ->assertJsonPath('data.status', 'promoted');

        $this->assertSame([
            ['revisionName' => $revision, 'percent' => 100, 'tag' => 'green'],
            ['revisionName' => 'shop-staging-00001', 'percent' => 0, 'tag' => 'previous'],
        ], $this->lastTraffic('shop-staging'));
        $this->assertSame([], $this->trafficPatches('shop'), 'production\'s service is not touched');
        $this->assertTrue(collect($this->k8sCalls)->contains(fn ($c) => $c['method'] === 'PATCH' && str_ends_with($c['path'], '/podautoscalers/shop-staging-00001')),
            'the previous revision of staging is kept warm');
        $this->assertSame(['active', 'running', 'active'], [$candidate->fresh()->status, $stable->fresh()->status, $production->fresh()->status]);

        // Rolled back on staging only
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/rollback")
            ->assertOk()
            ->assertJsonPath('data.status', 'rolled_back');
        $this->assertSame([['revisionName' => 'shop-staging-00001', 'percent' => 100]], $this->lastTraffic('shop-staging'));
        $this->assertSame(['active', 'active'], [$stable->fresh()->status, $production->fresh()->status]);
    }

    public function test_an_abort_of_an_environment_s_rollout_keeps_its_traffic(): void
    {
        [$project, , $production, $stable, $candidate] = $this->rollingOutStaging(['strategy' => 'canary']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/abort")
            ->assertOk()
            ->assertJsonPath('data.status', 'aborted');

        $this->assertSame([['revisionName' => 'shop-staging-00001', 'percent' => 100]], $this->lastTraffic('shop-staging'));
        $this->assertSame(['active', 'active'], [$stable->fresh()->status, $production->fresh()->status]);
        $this->assertFalse($candidate->fresh()->isLive());
    }

    public function test_a_production_rollout_never_ends_an_environment_s_deployment(): void
    {
        $project = $this->rolloutProject('shop', 'http', ['strategy' => 'blue_green', 'blue_green' => ['keep_previous_minutes' => 0]]);
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging', 'overrides' => ['deploy_strategy' => ['strategy' => 'direct']]]);
        $v1 = $this->rolloutVersion($project, 1);
        $v2 = $this->rolloutVersion($project, 2);
        $production = $this->rolloutDeployment($project, $v1, 'active');
        $stagingLive = $this->environmentDeployment($project, $staging, $v1, 'active');
        $this->servedBy('shop-00001');
        $this->fakeRolloutCluster();

        $candidate = Deployment::query()->findOrFail($this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v2->id])->assertAccepted()->json('id'));
        app(KnativeDeployService::class)->deploy($project->fresh(), $v2, $candidate);
        $this->revisionReady('shop-v2-'.substr(strtolower($candidate->id), -6));
        (new CheckDeploymentReadyJob($candidate->fresh(), $project->fresh()))->handle();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/deployments/{$candidate->id}/rollout/promote")->assertOk();

        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertFalse($production->fresh()->isLive() && $production->fresh()->status === 'active');
        $this->assertSame('active', $stagingLive->fresh()->status, 'staging runs on');
        $this->assertSame('direct', DeployTarget::environment($project->fresh(), $staging)->containerConfig->deploy_strategy['strategy'] ?? null);
    }

    public function test_a_promotion_into_an_environment_follows_the_target_s_strategy(): void
    {
        $project = $this->rolloutProject('shop', 'http');
        $dev = app(EnvironmentManager::class)->create($project, ['name' => 'dev']);
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging', 'overrides' => ['deploy_strategy' => ['strategy' => 'canary']]]);
        $v1 = $this->rolloutVersion($project, 1);
        $v2 = $this->rolloutVersion($project, 2);
        $this->environmentDeployment($project, $dev, $v2, 'active');
        $stable = $this->environmentDeployment($project, $staging, $v1, 'active');
        $this->servedBy('shop-staging-00001');
        $this->fakeRolloutCluster();

        $promotion = app(Promotions::class)->request($project->fresh(), $dev, $staging, $this->owner);

        $rollout = DeploymentRollout::query()->sole();
        $this->assertSame([DeploymentRollout::CANARY, $promotion->deployment_id, $stable->id, $staging->id],
            [$rollout->strategy, $rollout->deployment_id, $rollout->stable_deployment_id, $rollout->environment_id]);
        $this->assertSame('active', $stable->fresh()->status);

        // The environment goes: its rollouts with it
        app(EnvironmentManager::class)->delete($staging->fresh());
        $this->assertSame(0, DeploymentRollout::query()->count());
    }

    /**
     * Production v1 and staging v1 run; v2 is deployed to staging with its own $strategy.
     *
     * @return array{0: Project, 1: ProjectEnvironment, 2: Deployment, 3: Deployment, 4: Deployment, 5: string}
     */
    private function rollingOutStaging(array $strategy): array
    {
        $project = $this->rolloutProject('shop', 'http');
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging', 'overrides' => ['deploy_strategy' => $strategy]]);
        $v1 = $this->rolloutVersion($project, 1);
        $v2 = $this->rolloutVersion($project, 2);
        $production = $this->rolloutDeployment($project, $v1, 'active');
        $stable = $this->environmentDeployment($project, $staging, $v1, 'active');
        $this->servedBy('shop-staging-00001');
        $this->fakeRolloutCluster();

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v2->id, 'environment' => 'staging']);
        $this->assertContains($response->status(), [201, 202], $response->getContent());
        $candidate = Deployment::query()->findOrFail($response->json('id') ?? $response->json('data.id'));
        $this->assertSame($staging->id, $candidate->environment_id);

        app(KnativeDeployService::class)->deploy($project->fresh(), $v2, $candidate);
        $revision = 'shop-staging-v2-'.substr(strtolower($candidate->id), -6);

        return [$project->fresh(), $staging, $production, $stable, $candidate, $revision];
    }

    private function environmentDeployment(Project $project, ProjectEnvironment $environment, Version $version, string $status): Deployment
    {
        return Deployment::query()->create([
            'project_id' => $project->id,
            'environment_id' => $environment->id,
            'version_id' => $version->id,
            'status' => $status,
            'k8s_job_name' => "run-{$environment->workload_name}-v{$version->number}",
            'deployed_by' => 'dev',
            'knative_ready' => true,
            'ready_at' => now(),
        ]);
    }
}
