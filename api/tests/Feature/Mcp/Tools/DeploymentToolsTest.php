<?php

namespace Tests\Feature\Mcp\Tools;

use App\Events\DeploymentCreated;
use App\Jobs\BuildVersionJob;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\QueuedDeploy;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * deploy, rollback, deployments_list, deployment_status, deployment_stop. Deployments are
 * created by POST /projects/{id}/deploy (DeploymentCreated faked — nothing reaches a cluster);
 * the rollout is played by flipping the status between two polls.
 */
class DeploymentToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    /** Every pending deployment becomes $status on the next poll. */
    private function rolloutEndsAs(string $status): void
    {
        $this->onPoll = fn () => Deployment::query()->whereIn('status', ['pending', 'running'])->update(['status' => $status]);
    }

    // ── deploy ─────────────────────────────────────────────────────────────────

    public function test_deploy_waits_until_the_deployment_is_active(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);
        $v2 = $this->version($project, 2);
        $this->rolloutEndsAs('active');

        $result = $this->structured($this->callTool('deploy', ['project' => 'shop']));

        $this->assertSame('active', $result['deployment']['status']);
        $this->assertSame($v2->id, $result['version']['id']);
        Event::assertDispatched(DeploymentCreated::class);
        $this->assertSame($v2->id, Deployment::sole()->version_id);
        $this->assertTrue(AuditEvent::where('action', 'deployment.create')->exists());
    }

    public function test_deploy_streams_progress_over_sse(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);
        $this->rolloutEndsAs('active');
        [$token, $session] = $this->connect();

        $response = $this->mcpPost($token, [
            'jsonrpc' => '2.0', 'id' => 'd-1', 'method' => 'tools/call',
            'params' => ['name' => 'deploy', 'arguments' => ['project' => 'shop'], '_meta' => ['progressToken' => 42]],
        ], $session, [], 'application/json, text/event-stream');

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $messages = $this->mcpSseMessages($response);

        $progress = array_values(array_filter($messages, fn ($m) => ($m['method'] ?? null) === 'notifications/progress'));
        $this->assertNotEmpty($progress);
        $this->assertSame(42, $progress[0]['params']['progressToken']);
        $this->assertSame('active', collect($messages)->firstWhere('id', 'd-1')['result']['structuredContent']['deployment']['status']);
    }

    public function test_deploy_without_waiting_returns_the_pending_deployment(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);

        $result = $this->structured($this->callTool('deploy', ['project' => 'shop', 'wait' => false]));

        $this->assertSame('pending', $result['deployment']['status']);
    }

    public function test_a_failed_rollout_is_a_tool_error_that_points_at_the_logs(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);
        $this->rolloutEndsAs('failed');

        $error = $this->mcpToolError($this->callTool('deploy', ['project' => 'shop']));

        $this->assertStringContainsString('logs_tail', $error['message']);
    }

    public function test_deploy_waits_for_the_build_first(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1, 'building');
        $this->onPoll = function () use ($project) {
            // the build finishes like BuildVersionJob does it: a model update, which also bumps the
            // response cache of the project's versions (CacheInvalidationObserver)
            Version::where('project_id', $project->id)->get()->each->update(['build_status' => 'ready']);
            Deployment::query()->where('status', 'pending')->update(['status' => 'active']);
        };

        $result = $this->structured($this->callTool('deploy', ['project' => 'shop']));

        $this->assertSame('ready', $result['version']['build_status']);
        $this->assertSame('active', $result['deployment']['status']);
    }

    public function test_a_failed_build_is_reported_without_deploying(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1, 'failed', ['build_error' => 'compile error']);

        $error = $this->mcpToolError($this->callTool('deploy', ['project' => 'shop']));

        $this->assertStringContainsString('compile error', $error['message']);
        $this->assertSame(0, Deployment::count());
    }

    public function test_deploy_validation_and_permissions(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);

        $this->assertSchemaRejects('deploy', ['project' => 'shop', 'overrides' => ['memory_mb' => 1]], '/overrides/memory_mb');
        $this->assertSchemaRejects('deploy', [], '/project');
        $this->mcpToolError($this->callTool('deploy', ['project' => 'shop', 'version' => 'v9']));
        $this->assertToolHidden('deploy', ['projects.view', 'projects.update']);

        // A project role without deploy rights on this project: the route refuses
        $other = $this->containerProject('other');
        $this->customRole('outsider', ['tokens.manage']);
        $outsider = $this->userWithRole('outsider');
        $this->grantProject($outsider, $other, 'developer');
        $this->grantProject($outsider, $project, 'viewer');

        $error = $this->mcpToolError($this->callTool('deploy', ['project' => 'shop', 'wait' => false], $outsider));
        $this->assertSame(403, $error['status']);
        $this->assertContains('projects.deploy', $error['permissions']);
    }

    // ── rollback ───────────────────────────────────────────────────────────────

    public function test_rollback_goes_back_one_built_version_from_the_live_one(): void
    {
        $project = $this->containerProject('shop');
        $v1 = $this->version($project, 1);
        $v2 = $this->version($project, 2);
        $this->deployment($project, $v2, 'active');
        $this->rolloutEndsAs('active');

        $result = $this->structured($this->callTool('rollback', ['project' => 'shop']));

        $this->assertSame($v1->id, $result['version']['id']);
        $this->assertSame('active', $result['deployment']['status']);
        $this->assertTrue(AuditEvent::where('action', 'deployment.rollback')->exists(), 'audited as a rollback by the route');
    }

    public function test_rollback_to_a_named_version(): void
    {
        $project = $this->containerProject('shop');
        $v1 = $this->version($project, 1);
        $this->version($project, 2);
        $v3 = $this->version($project, 3);
        $this->deployment($project, $v3, 'active');
        $this->rolloutEndsAs('active');

        $result = $this->structured($this->callTool('rollback', ['project' => 'shop', 'version' => '1']));

        $this->assertSame($v1->id, $result['version']['id']);
    }

    /**
     * The region moved to a cluster with a registry of its own after v1 was built: the rollback
     * builds v1 again for the region's registry, follows its queued deploy to the deployment and
     * waits for that like for any other.
     */
    public function test_rollback_to_a_version_built_before_the_region_moved_waits_for_its_build_again(): void
    {
        Bus::fake([BuildVersionJob::class]);
        $moved = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true, 'registry_address' => '10.96.2.2:5000']);
        $project = $this->faasProject('orders');
        $project->forceFill(['knative_region_id' => $moved->id])->saveQuietly();
        $v1 = $this->version($project, 1, 'ready', ['build_image' => '10.96.1.1:5000/runr-builds/orders:v1']);
        $v2 = $this->version($project, 2, 'ready', ['build_image' => '10.96.2.2:5000/runr-builds/orders:v2']);
        $this->deployment($project, $v2, 'active');
        $this->onPoll = function () use ($v1) {
            $version = $v1->fresh();
            if ($version->build_status === 'pending') {
                // The build for the region's registry ends: the queued deploy starts the deployment
                $version->forceFill(['build_status' => 'building', 'build_image' => '10.96.2.2:5000/runr-builds/orders:v1'])->save();
                $version->forceFill(['build_status' => 'ready'])->save();

                return;
            }
            // Through the model: the deployment was read once already, its cached answer goes
            Deployment::query()->whereIn('status', ['pending', 'running'])->get()->each->update(['status' => 'active']);
        };

        $result = $this->structured($this->callTool('rollback', ['project' => 'orders']));

        $this->assertSame($v1->id, $result['version']['id']);
        $this->assertSame('active', $result['deployment']['status']);
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->version->is($v1));
        $this->assertSame(QueuedDeploy::DEPLOYED, QueuedDeploy::query()->sole()->status);
        $this->assertSame($result['deployment']['id'], QueuedDeploy::query()->sole()->deployment_id);
    }

    public function test_rollback_without_waiting_answers_with_the_queued_deploy(): void
    {
        Bus::fake([BuildVersionJob::class]);
        $moved = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true, 'registry_address' => '10.96.2.2:5000']);
        $project = $this->faasProject('orders');
        $project->forceFill(['knative_region_id' => $moved->id])->saveQuietly();
        $this->version($project, 1, 'ready', ['build_image' => '10.96.1.1:5000/runr-builds/orders:v1']);
        $v2 = $this->version($project, 2, 'ready', ['build_image' => '10.96.2.2:5000/runr-builds/orders:v2']);
        $this->deployment($project, $v2, 'active');

        $result = $this->structured($this->callTool('rollback', ['project' => 'orders', 'wait' => false]));

        $this->assertSame('building', $result['status']);
        $this->assertSame([QueuedDeploy::query()->sole()->id, QueuedDeploy::WAITING], [$result['queued_deploy']['id'], $result['queued_deploy']['status']]);
        $this->assertSame(1, Deployment::query()->count(), 'nothing is deployed before the build');
    }

    public function test_deploy_reports_a_queued_deploy_that_did_not_start(): void
    {
        Bus::fake([BuildVersionJob::class]);
        $moved = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true, 'registry_address' => '10.96.2.2:5000']);
        $project = $this->faasProject('orders');
        $project->forceFill(['knative_region_id' => $moved->id])->saveQuietly();
        $v1 = $this->version($project, 1, 'ready', ['build_image' => '10.96.1.1:5000/runr-builds/orders:v1']);
        // Its build for the region's registry fails
        $this->onPoll = fn () => $v1->fresh()->forceFill(['build_status' => 'failed', 'build_error' => 'pip: no matching distribution'])->save();

        $error = $this->mcpToolError($this->callTool('deploy', ['project' => 'orders', 'version' => '1']));

        $this->assertStringContainsString('The deploy of version 1 did not start: V1 could not be built: pip: no matching distribution', $error['message']);
        $this->assertSame(QueuedDeploy::FAILED, QueuedDeploy::query()->sole()->status);
    }

    public function test_rollback_errors_and_permissions(): void
    {
        $project = $this->containerProject('shop');
        $v1 = $this->version($project, 1);
        $this->deployment($project, $v1, 'active');

        $error = $this->mcpToolError($this->callTool('rollback', ['project' => 'shop']));
        $this->assertStringContainsString('versions_list', $error['message']);
        $this->assertSchemaRejects('rollback', ['project' => 'shop', 'steps_back' => 0], '/steps_back');
        $this->assertToolHidden('rollback', ['projects.view']);
    }

    // ── deployments_list / deployment_status ───────────────────────────────────

    public function test_deployments_are_listed_and_the_current_one_is_picked(): void
    {
        $project = $this->containerProject('shop');
        $v1 = $this->version($project, 1);
        $v2 = $this->version($project, 2);
        $active = $this->deployment($project, $v1, 'active');
        $this->travel(1)->seconds();
        $this->deployment($project, $v2, 'failed');

        $list = $this->structured($this->callTool('deployments_list', ['project' => 'shop']));
        $this->assertSame(['failed', 'active'], array_column($list['deployments'], 'status'));

        $current = $this->structured($this->callTool('deployment_status', ['project' => 'shop']));
        $this->assertSame($active->id, $current['deployment']['id'], 'the newest active deployment, not the newest one');

        $byId = $this->structured($this->callTool('deployment_status', ['project' => 'shop', 'deployment' => $active->id]));
        $this->assertSame('active', $byId['deployment']['status']);
    }

    public function test_status_of_a_project_without_deployments(): void
    {
        $this->containerProject('shop');

        $error = $this->mcpToolError($this->callTool('deployment_status', ['project' => 'shop']));

        $this->assertStringContainsString('deploy', $error['message']);
        $this->assertSchemaRejects('deployment_status', ['project' => 'shop', 'deployment' => str_repeat('x', 65)], '/deployment');
        $this->assertToolHidden('deployments_list', ['regions.view']);
        $this->assertToolHidden('deployment_status', ['regions.view']);
    }

    // ── deployment_stop ────────────────────────────────────────────────────────

    public function test_a_deployment_is_stopped_with_confirmation(): void
    {
        $project = $this->containerProject('shop');
        $v1 = $this->version($project, 1);
        $deployment = $this->deployment($project, $v1, 'failed');

        $this->assertSchemaRejects('deployment_stop', ['project' => 'shop'], '/confirm');
        $this->assertSchemaRejects('deployment_stop', ['project' => 'shop', 'confirm' => false], '/confirm');

        $result = $this->structured($this->callTool('deployment_stop', ['project' => 'shop', 'confirm' => true]));

        $this->assertSame($deployment->id, $result['stopped']['id']);
        $this->assertNull(Deployment::find($deployment->id));
        $this->assertTrue(AuditEvent::where('action', 'deployment.delete')->exists());
    }

    public function test_stopping_needs_projects_deploy(): void
    {
        $this->assertToolHidden('deployment_stop', ['projects.view']);
    }
}
