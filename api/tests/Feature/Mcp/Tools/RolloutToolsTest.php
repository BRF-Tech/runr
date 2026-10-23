<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\KnativeRegion;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * deployment_promote, deployment_abort, deployment_status with a rollout, and deploy with a
 * strategy (docs/deploy-strategies.md). The demo region has no cluster: the traffic
 * writes are no-ops, the rollout's state machine and the deployments' statuses are real.
 */
class RolloutToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    /**
     * shop: v1 active, v2 the new deployment of a rollout in $status.
     *
     * @return array{0: Project, 1: Deployment, 2: Deployment, 3: DeploymentRollout}
     */
    private function rolloutIn(string $status, string $strategy = 'blue_green', array $attributes = []): array
    {
        $project = $this->containerProject('shop');
        $stable = $this->deployment($project, $this->version($project, 1), $status === 'promoted' ? 'running' : 'active');
        $candidate = $this->deployment($project, $this->version($project, 2), $status === 'promoted' ? 'active' : ($status === 'pending' ? 'pending' : 'running'));

        $rollout = DeploymentRollout::query()->create(array_merge([
            'project_id' => $project->id,
            'deployment_id' => $candidate->id,
            'stable_deployment_id' => $stable->id,
            'region_id' => $project->knative_region_id,
            'strategy' => $strategy,
            'status' => $status,
            'options' => $strategy === 'canary' ? ['steps' => [10, 50, 100], 'interval_minutes' => 5, 'error_rate_threshold' => 5, 'p95_threshold_ms' => null]
                : ['auto_promote' => false, 'health_path' => null, 'health_checks' => 3, 'keep_previous_minutes' => 15],
            'revision' => 'shop-v2-abcdef',
            'stable_revision' => 'shop-00001',
            'tag' => $strategy === 'canary' ? 'canary' : 'green',
            'traffic_percent' => $status === 'promoted' ? 100 : 0,
        ], $attributes));

        return [$project, $stable, $candidate, $rollout];
    }

    public function test_deployment_promote_promotes_the_current_rollout(): void
    {
        [, $stable, $candidate] = $this->rolloutIn('waiting_promotion');

        $result = $this->structured($this->callTool('deployment_promote', ['project' => 'shop']));

        $this->assertSame('promoted', $result['rollout']['status']);
        $this->assertSame(['candidate' => 100, 'stable' => 0], $result['rollout']['traffic']);
        $this->assertSame('active', $candidate->fresh()->status);
        $this->assertSame('running', $stable->fresh()->status, 'kept warm for a rollback');
        $this->assertTrue(AuditEvent::query()->where('action', 'deployment.rollout.promote')->exists());
    }

    public function test_deployment_promote_refuses_a_rollout_that_is_not_ready(): void
    {
        $this->rolloutIn('pending');

        $error = $this->mcpToolError($this->callTool('deployment_promote', ['project' => 'shop']));

        $this->assertStringContainsString('cannot be promoted', $error['message']);
    }

    public function test_deployment_abort_drops_an_open_rollout(): void
    {
        [, $stable, $candidate] = $this->rolloutIn('progressing', 'canary', ['traffic_percent' => 10, 'step_index' => 0, 'step_started_at' => now()]);

        $result = $this->structured($this->callTool('deployment_abort', ['project' => 'shop', 'reason' => 'latency spike', 'confirm' => true]));

        $this->assertSame('abort', $result['action']);
        $this->assertSame('aborted', $result['rollout']['status']);
        $this->assertSame('latency spike', $result['rollout']['reason']);
        $this->assertSame('active', $stable->fresh()->status);
        $this->assertFalse($candidate->fresh()->isLive());
    }

    public function test_deployment_abort_after_a_promotion_rolls_back_to_the_kept_revision(): void
    {
        [, $stable, $candidate] = $this->rolloutIn('promoted', 'blue_green', ['promoted_at' => now(), 'keep_until' => now()->addMinutes(15), 'next_check_at' => now()->addMinutes(15)]);

        $result = $this->structured($this->callTool('deployment_abort', ['project' => 'shop', 'confirm' => true]));

        $this->assertSame('rollback', $result['action']);
        $this->assertSame('rolled_back', $result['rollout']['status']);
        $this->assertSame('active', $stable->fresh()->status);
        $this->assertFalse($candidate->fresh()->isLive());
    }

    public function test_deployment_abort_needs_the_confirmation(): void
    {
        $this->rolloutIn('waiting_promotion');

        $this->assertSchemaRejects('deployment_abort', ['project' => 'shop'], '/confirm');
        $this->assertSame('waiting_promotion', DeploymentRollout::query()->sole()->status);
    }

    public function test_deployment_status_shows_the_rollout(): void
    {
        [, $stable] = $this->rolloutIn('progressing', 'canary', ['traffic_percent' => 10, 'step_index' => 0, 'step_started_at' => now()]);

        $result = $this->callTool('deployment_status', ['project' => 'shop']);
        $structured = $this->structured($result);

        // The current deployment is the one that serves; the rollout comes with it
        $this->assertSame($stable->id, $structured['deployment']['id']);
        $this->assertSame('progressing', $structured['rollout']['status']);
        $this->assertSame(['candidate' => 10, 'stable' => 90], $structured['rollout']['traffic']);
        $this->assertStringContainsString('10 % of the traffic', json_encode($result['content']));
    }

    public function test_tools_without_a_rollout_explain_the_direct_deploy(): void
    {
        $project = $this->containerProject('shop');
        $this->deployment($project, $this->version($project, 1));

        $error = $this->mcpToolError($this->callTool('deployment_promote', ['project' => 'shop']));

        $this->assertStringContainsString('deploys directly', $error['message']);
    }

    public function test_deploy_with_a_strategy_stops_waiting_once_the_rollout_is_under_way(): void
    {
        // A region with a cluster (the demo region deploys directly); the deploy job is faked
        $region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true,
            'kubeconfig' => Yaml::dump(['apiVersion' => 'v1', 'kind' => 'Config', 'clusters' => [], 'users' => [], 'contexts' => []]),
        ]);
        $project = $this->containerProject('api', ['GREETING' => 'hello'], $region);
        $this->deployment($project, $this->version($project, 1))->update(['knative_ready' => true]);
        $this->version($project, 2);
        $this->onPoll = function () {
            // the new revision became ready: the rollout waits for its promotion
            DeploymentRollout::query()->where('status', 'pending')->each(function (DeploymentRollout $rollout) {
                $rollout->update(['status' => 'waiting_promotion']);
                Deployment::query()->whereKey($rollout->deployment_id)->update(['status' => 'running', 'knative_ready' => true]);
            });
        };

        $result = $this->callTool('deploy', ['project' => 'api', 'strategy' => 'blue_green']);
        $structured = $this->structured($result);

        $this->assertSame('running', $structured['deployment']['status']);
        $this->assertSame('blue_green', $structured['deployment']['rollout']['strategy']);
        $this->assertSame('waiting_promotion', $structured['deployment']['rollout']['status']);
        $this->assertStringContainsString('deployment_promote', json_encode($result['content']));
    }
}
