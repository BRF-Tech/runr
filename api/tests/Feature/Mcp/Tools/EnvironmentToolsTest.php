<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Deployment;
use App\Models\EnvironmentPromotion;
use App\Models\ProjectEnvironment;
use App\Services\Environments\EnvironmentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * environments_list, environment_create, environment_delete, deploy / rollback with an environment,
 * promote and promotion_approve (docs/environments.md, docs/mcp.md). Deployments are created by the
 * API (DeploymentCreated faked); the rollout is played by flipping the status between two polls.
 */
class EnvironmentToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    public function test_environments_are_created_listed_and_deleted(): void
    {
        $this->containerProject('shop');

        $created = $this->structured($this->callTool('environment_create', [
            'project' => 'shop', 'name' => 'staging', 'git_branch' => 'develop', 'overrides' => ['min_scale' => 0, 'env_vars' => ['DEBUG' => '1']],
        ]));
        $this->assertSame('staging', $created['environment']['name']);
        $this->assertSame('develop', $created['environment']['git_branch']);

        $listed = $this->structured($this->callTool('environments_list', ['project' => 'shop']));
        $this->assertSame(['staging', 'production'], array_column($listed['environments'], 'name'));
        $this->assertTrue($listed['environments'][1]['requires_approval']);

        $this->assertSchemaRejects('environment_delete', ['project' => 'shop', 'environment' => 'staging'], '/confirm');
        $this->structured($this->callTool('environment_delete', ['project' => 'shop', 'environment' => 'staging', 'confirm' => true]));
        $this->assertSame(['production'], ProjectEnvironment::query()->pluck('name')->all());
    }

    public function test_environment_update_changes_its_overrides_and_its_own_timed_warmer(): void
    {
        $project = $this->containerProject('shop');
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging', 'overrides' => ['env_vars' => ['DEBUG' => '1'], 'min_scale' => 0]]);

        $updated = $this->structured($this->callTool('environment_update', [
            'project' => 'shop', 'environment' => 'staging', 'requires_approval' => true,
            'overrides' => ['max_scale' => 5, 'scale_schedule' => [['cron' => '0 9 * * 1-5', 'min_scale' => 2], ['cron' => '0 18 * * 1-5', 'min_scale' => 0]]],
        ]));
        $this->assertSame([['cron' => '0 9 * * 1-5', 'min_scale' => 2], ['cron' => '0 18 * * 1-5', 'min_scale' => 0]], $updated['environment']['scale_schedule']);
        $this->assertFalse($updated['environment']['scale_schedule_inherited']);
        $overrides = $staging->fresh()->overrideValues();
        $this->assertSame(['DEBUG' => '1'], $overrides['env_vars'], 'the other overrides stay');
        $this->assertSame([0, 5], [$overrides['min_scale'], $overrides['max_scale']]);
        $this->assertTrue($staging->fresh()->requires_approval);

        // [] keeps nothing warm there; null follows the project's schedule again
        $this->callTool('environment_update', ['project' => 'shop', 'environment' => 'staging', 'overrides' => ['scale_schedule' => []]]);
        $this->assertSame([], $staging->fresh()->overrideValues()['scale_schedule']);
        $back = $this->structured($this->callTool('environment_update', ['project' => 'shop', 'environment' => 'staging', 'overrides' => ['scale_schedule' => null]]));
        $this->assertTrue($back['environment']['scale_schedule_inherited']);
        $this->assertArrayNotHasKey('scale_schedule', $staging->fresh()->overrideValues());

        $error = $this->mcpToolError($this->callTool('environment_update', ['project' => 'shop', 'environment' => 'production', 'overrides' => ['min_scale' => 1]]));
        $this->assertStringContainsString('project_update', $error['message']);
    }

    public function test_deploy_to_an_environment_and_roll_it_back(): void
    {
        $project = $this->containerProject('shop');
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging']);
        $v1 = $this->version($project, 1);
        $v2 = $this->version($project, 2);
        $this->onPoll = fn () => Deployment::query()->whereIn('status', ['pending', 'running'])->get()->each->update(['status' => 'active']);

        $deployed = $this->structured($this->callTool('deploy', ['project' => 'shop', 'environment' => 'staging']));
        $this->assertSame($v2->id, $deployed['version']['id']);
        $this->assertSame($staging->id, Deployment::query()->findOrFail($deployed['deployment']['id'])->environment_id);
        $this->assertSame(0, Deployment::query()->whereNull('environment_id')->count(), 'production is not deployed');

        $rolled = $this->structured($this->callTool('rollback', ['project' => 'shop', 'environment' => 'staging']));
        $this->assertSame($v1->id, $rolled['version']['id']);
        $this->assertSame($staging->id, Deployment::query()->findOrFail($rolled['deployment']['id'])->environment_id);
    }

    public function test_a_promotion_waits_for_another_approver(): void
    {
        $project = $this->containerProject('shop');
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging']);
        $version = $this->version($project, 1);
        Deployment::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'environment_id' => $staging->id,
            'status' => 'active', 'k8s_job_name' => 'run', 'deployed_by' => 'dev']);

        $dry = $this->structured($this->callTool('promote', ['project' => 'shop', 'from' => 'staging', 'dry_run' => true]));
        $this->assertSame('production', $dry['target']['environment']);
        $this->assertSame(0, EnvironmentPromotion::query()->count(), 'a dry run requests nothing');

        $requested = $this->structured($this->callTool('promote', ['project' => 'shop', 'from' => 'staging', 'message' => 'ship it']));
        $this->assertSame(EnvironmentPromotion::PENDING_APPROVAL, $requested['promotion']['status']);

        // The owner requested it: the owner cannot approve it
        $error = $this->mcpToolError($this->callTool('promotion_approve', ['project' => 'shop', 'promotion' => $requested['promotion']['id']]));
        $this->assertSame(403, $error['status']);

        $approver = $this->userWithRole('admin');
        $waiting = $this->structured($this->callTool('promotion_approve', ['project' => 'shop'], $approver));
        $this->assertCount(1, $waiting['promotions']);
        $approved = $this->structured($this->callTool('promotion_approve', ['project' => 'shop', 'promotion' => $requested['promotion']['id'], 'comment' => 'go'], $approver));
        $this->assertSame(EnvironmentPromotion::DEPLOYING, $approved['promotion']['status']);
        $this->assertSame($version->id, Deployment::query()->whereNull('environment_id')->sole()->version_id);

        $this->assertToolHidden('promotion_approve', ['projects.view', 'environments.promote']);
    }
}
