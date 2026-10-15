<?php

namespace Tests\Feature\Workflows;

use App\Models\Deployment;
use App\Models\Version;
use App\Services\GitService;
use App\Workflows\Engine\WorkflowException;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Jobs\SyncWorkflowsJob;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

/** Definition sync: SDK introspection, manifest fallback, archiving, post-deploy sync. */
class WorkflowSyncTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    public function test_sync_reads_definitions_from_the_function(): void
    {
        $this->fn->define('orders', fn () => 1, [
            'description' => 'Order pipeline',
            'triggers' => [['event' => 'order.created'], ['cron' => '*/10 * * * *']],
            'retries' => 2,
            'cancel_on' => [['event' => 'order.cancelled', 'match' => 'data.id']],
        ]);
        $this->fn->define('cleanup', fn () => 1);

        $result = app(WorkflowRegistry::class)->sync($this->project);

        $this->assertSame('sdk', $result['source']);
        $this->assertSame(['cleanup', 'orders'], Workflow::where('project_id', $this->project->id)->orderBy('name')->pluck('name')->all());

        $orders = Workflow::where('name', 'orders')->firstOrFail();
        $this->assertSame('Order pipeline', $orders->description);
        $this->assertSame(3, $orders->maxAttempts());
        $this->assertSame('sdk', $orders->source);
        $this->assertSame(
            ['cancel' => 1, 'cron' => 1, 'event' => 1],
            WorkflowTrigger::where('workflow_id', $orders->id)->get()->countBy('type')->sortKeys()->all(),
        );

        $app = WorkflowApp::forProject($this->project)->fresh();
        $this->assertSame('ok', $app->sync_status);
        $this->assertSame('fake-php', $app->sdk['name']);
        $this->assertNotNull($app->last_synced_at);
        $this->assertSame('introspect', $this->fn->lastRequest()->type);
    }

    public function test_workflows_no_longer_served_are_archived_and_pausing_survives_a_sync(): void
    {
        $this->fn->define('a', fn () => 1)->define('b', fn () => 1);
        app(WorkflowRegistry::class)->sync($this->project);

        $a = Workflow::where('name', 'a')->firstOrFail();
        $a->forceFill(['is_active' => false])->save();

        unset($this->fn->workflows['b']);
        app(WorkflowRegistry::class)->sync($this->project);

        $this->assertFalse($a->fresh()->is_active, 'pause kept');
        $b = Workflow::where('name', 'b')->firstOrFail();
        $this->assertNotNull($b->archived_at);
        $this->assertFalse($b->canStart());
        $this->assertSame(0, WorkflowTrigger::where('workflow_id', $b->id)->count());

        // Served again → un-archived
        $this->fn->define('b', fn () => 1);
        app(WorkflowRegistry::class)->sync($this->project);
        $this->assertNull($b->fresh()->archived_at);
    }

    public function test_an_unreachable_function_falls_back_to_the_code_manifest(): void
    {
        $this->fn->failNextWith = 503;
        $this->fn->failNextCount = 1;

        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready', 'commit_id' => 'abc123']);
        $git = Mockery::mock(GitService::class);
        $git->shouldReceive('getFileAtVersion')
            ->with(Mockery::on(fn ($p) => $p->id === $this->project->id), 'abc123', 'runr.workflows.json')
            ->andReturn(json_encode(['workflows' => [['name' => 'from-manifest', 'triggers' => [['event' => 'x.y']]]]]));
        $this->app->instance(GitService::class, $git);

        $result = app(WorkflowRegistry::class)->sync($this->project->fresh());

        $this->assertSame('manifest', $result['source']);
        $this->assertSame('manifest', Workflow::where('name', 'from-manifest')->firstOrFail()->source);
        $this->assertNotNull($version);
    }

    public function test_sync_failure_is_recorded(): void
    {
        $this->fn->failNextWith = 500;
        $this->fn->failNextCount = 1;

        try {
            app(WorkflowRegistry::class)->sync($this->project);
            $this->fail('sync did not fail');
        } catch (WorkflowException $e) {
            $this->assertSame(422, $e->status);
        }

        $app = WorkflowApp::forProject($this->project)->fresh();
        $this->assertSame('error', $app->sync_status);
        $this->assertStringContainsString('HTTP 500', $app->sync_error);
    }

    public function test_invalid_definitions_from_the_sdk_are_refused(): void
    {
        $this->fn->define('ok', fn () => 1, ['retries' => 'many']);

        $this->expectException(WorkflowException::class);
        app(WorkflowRegistry::class)->sync($this->project);
    }

    public function test_a_workflow_project_deployment_becoming_active_queues_a_sync(): void
    {
        Queue::fake();
        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = $this->project->deployments()->create(['version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev']);

        $deployment->update(['status' => 'active']);

        Queue::assertPushed(SyncWorkflowsJob::class, fn ($job) => $job->projectId === $this->project->id);
    }

    public function test_other_projects_do_not_sync_on_deploy(): void
    {
        Queue::fake();
        $this->project->containerConfig->update(['trigger_type' => 'http']);
        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = $this->project->deployments()->create(['version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev']);

        $deployment->update(['status' => 'active']);

        Queue::assertNotPushed(SyncWorkflowsJob::class);
        $this->assertInstanceOf(Deployment::class, $deployment);
    }
}
