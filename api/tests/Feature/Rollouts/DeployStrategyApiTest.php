<?php

namespace Tests\Feature\Rollouts;

use App\Models\AuditEvent;
use App\Models\ContainerConfig;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRolloutCluster;
use Tests\TestCase;

/**
 * GET / PUT /projects/{project}/deploy-strategy (docs/deploy-strategies.md): the
 * defaults, partial updates that keep the other options, the validation, the trigger rule, the
 * permissions — and no version is created by a strategy change.
 */
class DeployStrategyApiTest extends TestCase
{
    use ActsWithRoles, FakesRolloutCluster, RefreshDatabase;

    public function test_a_project_deploys_directly_until_a_strategy_is_set(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->rolloutProject();

        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}/deploy-strategy")
            ->assertOk()
            ->assertJsonPath('data.strategy', 'direct')
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.trigger_type', 'http')
            ->assertJsonPath('data.tracing', false)
            ->assertJsonPath('data.blue_green.keep_previous_minutes', 15)
            ->assertJsonPath('data.canary.steps', [10, 50, 100])
            ->assertJsonPath('data.defaults.strategy', 'direct');

        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.deploy_strategy', 'direct');
    }

    public function test_blue_green_with_its_options_is_stored_without_a_new_version(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->rolloutProject();
        $versions = Version::query()->count();

        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", [
            'strategy' => 'blue-green',
            'blue_green' => ['auto_promote' => true, 'health_path' => '/healthz', 'health_checks' => 5, 'keep_previous_minutes' => 30],
        ])
            ->assertOk()
            ->assertJsonPath('data.strategy', 'blue_green')
            ->assertJsonPath('data.blue_green', ['auto_promote' => true, 'health_path' => '/healthz', 'health_checks' => 5, 'keep_previous_minutes' => 30])
            ->assertJsonPath('data.canary.steps', [10, 50, 100]);

        $stored = ContainerConfig::query()->where('project_id', $project->id)->sole()->deploy_strategy;
        $this->assertSame('blue_green', $stored['strategy']);
        $this->assertSame('/healthz', $stored['blue_green']['health_path']);
        $this->assertSame($versions, Version::query()->count(), 'a strategy change creates no version');
        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}")->assertJsonPath('data.deploy_strategy', 'blue_green');

        $audit = AuditEvent::query()->where('action', 'project.deploy_strategy.update')->sole();
        $this->assertSame('blue_green', $audit->metadata['strategy'] ?? null);
    }

    public function test_a_partial_update_keeps_the_other_options(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->rolloutProject('shop', 'http', ['strategy' => 'canary', 'canary' => ['steps' => [5, 100], 'p95_threshold_ms' => 900]]);

        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", ['canary' => ['interval_minutes' => 10, 'error_rate_threshold' => null]])
            ->assertOk()
            ->assertJsonPath('data.strategy', 'canary')
            ->assertJsonPath('data.canary', ['steps' => [5, 100], 'interval_minutes' => 10, 'error_rate_threshold' => null, 'p95_threshold_ms' => 900]);
    }

    public function test_invalid_options_are_refused_field_by_field(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->rolloutProject();

        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", [
            'strategy' => 'rolling',
            'blue_green' => ['health_path' => 'https://elsewhere.test/health', 'health_checks' => 0, 'keep_previous_minutes' => 5000],
            'canary' => ['steps' => [50, 20], 'interval_minutes' => 0, 'error_rate_threshold' => 120, 'p95_threshold_ms' => 0],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'strategy', 'blue_green.health_path', 'blue_green.health_checks', 'blue_green.keep_previous_minutes',
                'canary.steps', 'canary.interval_minutes', 'canary.error_rate_threshold', 'canary.p95_threshold_ms',
            ]);

        $this->assertNull(ContainerConfig::query()->where('project_id', $project->id)->sole()->deploy_strategy, 'nothing stored');
    }

    public function test_queue_and_schedule_functions_cannot_use_a_strategy(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->rolloutProject('worker', 'schedule');

        $this->actingAs($owner, 'sanctum')->getJson("/api/projects/{$project->id}/deploy-strategy")
            ->assertOk()
            ->assertJsonPath('data.eligible', false);

        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", ['strategy' => 'canary'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('strategy');

        // direct (and the options for later) can always be written
        $this->actingAs($owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", ['strategy' => 'direct', 'canary' => ['steps' => [20, 100]]])
            ->assertOk()
            ->assertJsonPath('data.canary.steps', [20, 100]);
    }

    public function test_viewers_read_the_strategy_and_only_updaters_change_it(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $project = $this->rolloutProject();

        $this->actingAs($viewer, 'sanctum')->getJson("/api/projects/{$project->id}/deploy-strategy")->assertOk();
        $this->actingAs($viewer, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", ['strategy' => 'canary'])->assertForbidden();
    }
}
