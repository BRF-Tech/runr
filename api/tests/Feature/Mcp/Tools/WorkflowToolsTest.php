<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\ContainerConfig;
use App\Models\Project;
use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Workflows\RecordingDispatcher;
use Tests\TestCase;

/**
 * workflows_list, workflow_run_start, workflow_runs_list, workflow_run_status, event_send — the
 * durable workflow routes, with the engine's dispatches recorded instead of executed.
 */
class WorkflowToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private Project $project;

    private RecordingDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        $this->dispatcher = new RecordingDispatcher;
        $this->app->instance(WorkflowDispatcher::class, $this->dispatcher);

        $this->project = Project::create(['name' => 'wf-orders', 'service_type' => 'caas']);
        $this->project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $this->project->id, 'image' => 'ghcr.io/example/workflows:1',
            'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'workflow',
        ]);

        app(WorkflowRegistry::class)->declare($this->project, [
            ['name' => 'process-order', 'triggers' => [['event' => 'order.created']]],
            ['name' => 'nightly-report', 'triggers' => [['cron' => '0 2 * * *']]],
        ]);
    }

    public function test_workflows_are_listed(): void
    {
        $result = $this->structured($this->callTool('workflows_list', ['project' => 'wf-orders']));

        $this->assertSame(['nightly-report', 'process-order'], array_column($result['workflows'], 'name'));
        $this->assertTrue($result['app']['workflow_project']);
    }

    public function test_a_run_is_started_once_per_idempotency_key(): void
    {
        $first = $this->structured($this->callTool('workflow_run_start', [
            'project' => 'wf-orders', 'workflow' => 'process-order', 'data' => ['order_id' => 'o-1'], 'idempotency_key' => 'o-1',
        ]));
        $this->assertTrue($first['created']);
        $this->assertSame('process-order', $first['run']['workflow']);
        $this->assertCount(1, $this->dispatcher->history);

        $again = $this->structured($this->callTool('workflow_run_start', [
            'project' => 'wf-orders', 'workflow' => 'process-order', 'data' => ['order_id' => 'o-1'], 'idempotency_key' => 'o-1',
        ]));
        $this->assertFalse($again['created']);
        $this->assertSame($first['run']['id'], $again['run']['id']);
        $this->assertSame(1, WorkflowRun::count());
    }

    public function test_runs_are_listed_and_one_is_shown_with_its_steps(): void
    {
        $started = $this->structured($this->callTool('workflow_run_start', ['project' => 'wf-orders', 'workflow' => 'process-order']));
        $run = WorkflowRun::findOrFail($started['run']['id']);
        $run->update(['status' => WorkflowRun::FAILED, 'error' => ['name' => 'Error', 'message' => 'card declined']]);
        WorkflowStep::create([
            'run_id' => $run->id, 'step_key' => 'charge-card', 'name' => 'charge-card', 'type' => 'run',
            'status' => 'failed', 'position' => 0, 'attempt' => 3, 'max_attempts' => 3,
            'error' => ['name' => 'Error', 'message' => 'card declined'],
        ]);

        $failed = $this->structured($this->callTool('workflow_runs_list', ['project' => 'wf-orders', 'status' => ['failed']]));
        $this->assertSame([$run->id], array_column($failed['runs'], 'id'));
        $this->assertSame([], $this->structured($this->callTool('workflow_runs_list', ['project' => 'wf-orders', 'status' => ['completed']]))['runs']);

        $detail = $this->structured($this->callTool('workflow_run_status', ['project' => 'wf-orders', 'run' => $run->id]));
        $this->assertSame('failed', $detail['run']['status']);
        $this->assertSame('charge-card', $detail['run']['steps'][0]['name']);
        $this->assertSame('card declined', $detail['run']['steps'][0]['error']['message']);
    }

    public function test_an_event_starts_its_workflows(): void
    {
        $result = $this->structured($this->callTool('event_send', ['name' => 'order.created', 'data' => ['order_id' => 'o-2'], 'id' => 'evt-1']));

        $this->assertNotEmpty($result['events']);
        $this->assertSame(1, WorkflowRun::count(), 'process-order triggered by order.created');
    }

    public function test_workflow_validation(): void
    {
        $this->mcpToolError($this->callTool('workflow_run_start', ['project' => 'wf-orders', 'workflow' => 'nope']));
        $this->assertSchemaRejects('workflow_run_start', ['project' => 'wf-orders', 'workflow' => 'process-order', 'data' => [1, 2]], '/data');
        $this->assertSchemaRejects('workflow_runs_list', ['project' => 'wf-orders', 'status' => ['exploded']]);
        $this->mcpToolError($this->callTool('workflow_run_status', ['project' => 'wf-orders', 'run' => 'not-a-run']));

        // Reserved event names are the route's error
        $this->mcpToolError($this->callTool('event_send', ['name' => 'runr/cron']));
    }

    public function test_workflow_permissions(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->structured($this->callTool('workflows_list', ['project' => 'wf-orders'], $viewer));
        $this->assertToolHidden('workflow_run_start', ['*'], ['project' => 'wf-orders', 'workflow' => 'process-order'], $viewer);
        $this->assertToolHidden('event_send', ['*'], ['name' => 'order.created'], $viewer);
        $this->assertToolHidden('workflows_list', ['projects.view']);
    }
}
