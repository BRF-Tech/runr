<?php

namespace Tests\Feature\Workflows;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\User;
use App\Providers\WorkflowServiceProvider;
use App\Support\Rbac\PermissionCatalog;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeNonRetriable;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/** HTTP API of workflows, runs, events and usage (routes/workflows.php). */
class WorkflowApiTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->user = User::factory()->create();
        // Signed in without an API token (a Sanctum::actingAs() mock token carries no abilities,
        // so RBAC grants it nothing)
        $this->actingAs($this->user, 'sanctum');
    }

    private function base(): string
    {
        return "/api/projects/{$this->project->id}";
    }

    public function test_permissions_are_part_of_the_rbac_catalog(): void
    {
        foreach (WorkflowServiceProvider::PERMISSIONS as $ability) {
            $this->assertContains($ability, PermissionCatalog::allKeys(), $ability);
            $this->assertTrue(Gate::has($ability), $ability);
            // The first user of the install is its Owner
            $this->assertTrue(Gate::forUser($this->user)->allows($ability, $this->project), $ability);
        }
    }

    public function test_list_and_declare_workflows(): void
    {
        $this->putJson($this->base().'/workflows', ['workflows' => [
            ['name' => 'orders', 'triggers' => [['event' => 'order.created']], 'retries' => 2, 'concurrency' => 5],
            ['name' => 'nightly', 'triggers' => [['cron' => '0 2 * * *']]],
        ]])->assertOk()->assertJsonPath('message', __('workflows.declared'));

        $res = $this->getJson($this->base().'/workflows')->assertOk();
        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'nightly')
            ->assertJsonPath('data.1.name', 'orders')
            ->assertJsonPath('data.1.retries', 2)
            ->assertJsonPath('data.1.concurrency.0.limit', 5)
            ->assertJsonPath('data.1.is_active', true)
            ->assertJsonPath('app.is_workflow_project', true)
            ->assertJsonPath('app.endpoint_url', 'https://fn.test/')
            ->assertJsonPath('app.sync_status', 'never');
        $this->assertArrayNotHasKey('signing_key', $res->json('app'));

        $this->getJson($this->base().'/workflows/orders')->assertOk()->assertJsonPath('data.triggers.0.event', 'order.created');
        $this->getJson($this->base().'/workflows/unknown')->assertNotFound();
    }

    public function test_declare_rejects_invalid_definitions(): void
    {
        $this->putJson($this->base().'/workflows', ['workflows' => [['name' => 'bad', 'retries' => -1]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'definition_invalid');

        $this->putJson($this->base().'/workflows', [])->assertStatus(422);
    }

    public function test_sync_endpoint(): void
    {
        $this->fn->define('synced-one', fn () => 1);

        $this->postJson($this->base().'/workflows/sync')
            ->assertOk()
            ->assertJsonPath('source', 'sdk')
            ->assertJsonPath('data.0.name', 'synced-one')
            ->assertJsonPath('app.sync_status', 'ok');
    }

    public function test_start_run_with_idempotency(): void
    {
        $this->defineWorkflow('greet', fn ($step, $event) => 'hi '.$event->data->name);

        $first = $this->postJson($this->base().'/workflows/greet/runs', ['data' => ['name' => 'Ada'], 'idempotency_key' => 'k1'])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.workflow.name', 'greet')
            ->assertJsonPath('data.trigger_source', 'api')
            ->json('data.id');

        $this->postJson($this->base().'/workflows/greet/runs', ['data' => ['name' => 'Bob'], 'idempotency_key' => 'k1'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $first);

        $this->postJson($this->base().'/workflows/greet/runs', ['data' => ['name' => 'T'], 'test' => true])
            ->assertCreated()
            ->assertJsonPath('data.trigger_source', 'test');

        $this->assertSame((string) $this->user->id, WorkflowRun::find($first)->started_by);

        $this->drain();
        $this->getJson($this->base()."/workflow-runs/{$first}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.output', 'hi Ada')
            ->assertJsonPath('data.event.data.name', 'Ada');
    }

    public function test_start_run_errors(): void
    {
        $wf = $this->defineWorkflow('paused-api', fn () => 1);

        $this->postJson($this->base().'/workflows/nope/runs', [])->assertNotFound();
        $this->postJson($this->base().'/workflows/paused-api/runs', ['data' => [1, 2]])->assertStatus(422);

        $this->patchJson($this->base().'/workflows/paused-api', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson($this->base().'/workflows/paused-api/runs', [])->assertStatus(409)->assertJsonPath('code', 'workflow_inactive');

        config(['workflows.limits.max_event_bytes' => 64]);
        // refresh: the PATCH above paused the row, the model in memory still says active (no change to save)
        $wf->refresh()->forceFill(['is_active' => true])->save();
        $this->postJson($this->base().'/workflows/paused-api/runs', ['data' => ['x' => str_repeat('y', 100)]])
            ->assertStatus(413)
            ->assertJsonPath('code', 'payload_too_large');
    }

    public function test_run_detail_lists_and_filters(): void
    {
        $this->defineWorkflow('two-steps', function (FakeStepTools $step) {
            $step->run('first', fn () => 1);
            $step->sleep('rest', '1h');

            return 2;
        });
        $this->defineWorkflow('fails', fn () => throw new FakeNonRetriable('bad input'));

        $sleeping = $this->startRun(Workflow::where('name', 'two-steps')->first());
        $failed = $this->startRun(Workflow::where('name', 'fails')->first());
        $this->drain();

        $detail = $this->getJson($this->base()."/workflow-runs/{$sleeping->id}")->assertOk();
        $detail->assertJsonPath('data.status', 'sleeping')
            ->assertJsonCount(2, 'data.steps')
            ->assertJsonPath('data.steps.0.step_key', 'first')
            ->assertJsonPath('data.steps.0.status', 'completed')
            ->assertJsonPath('data.steps.1.type', 'sleep')
            ->assertJsonPath('data.steps.1.status', 'sleeping');
        $this->assertNotNull($detail->json('data.steps.1.wake_at'));

        $this->getJson($this->base().'/workflow-runs?status=failed')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.error.message', 'bad input');
        $this->getJson($this->base().'/workflow-runs?status=sleeping,failed')->assertJsonCount(2, 'data');
        $this->getJson($this->base().'/workflow-runs?workflow=two-steps')->assertJsonCount(1, 'data');
        $this->getJson($this->base().'/workflows/fails/runs')->assertJsonCount(1, 'data');
        $this->getJson($this->base()."/workflow-runs?q={$failed->id}")->assertJsonCount(1, 'data');
        $this->getJson($this->base().'/workflow-runs?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->base().'/workflow-runs?source=nonsense')->assertStatus(422);

        // A run of another project is not visible here
        $other = $this->makeWorkflowProject('other-project');
        $this->getJson("/api/projects/{$other->id}/workflow-runs/{$sleeping->id}")->assertNotFound();
    }

    public function test_cancel_retry_and_replay_endpoints(): void
    {
        $shouldFail = true;
        $this->defineWorkflow('ops', function (FakeStepTools $step) use (&$shouldFail) {
            $step->run('a', fn () => 'a');

            return $step->run('b', function () use (&$shouldFail) {
                if ($shouldFail) {
                    throw new FakeNonRetriable('b failed');
                }

                return 'b';
            });
        });
        $this->defineWorkflow('napper', function (FakeStepTools $step) {
            $step->sleep('z', '1d');

            return 1;
        });

        $napping = $this->startRun(Workflow::where('name', 'napper')->first());
        $failing = $this->startRun(Workflow::where('name', 'ops')->first());
        $this->drain();

        $this->postJson($this->base()."/workflow-runs/{$napping->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($this->base()."/workflow-runs/{$napping->id}/cancel")->assertStatus(409);
        $this->assertStringContainsString((string) ($this->user->name ?? $this->user->email), $napping->fresh()->error['message']);

        $this->assertSame('failed', $failing->fresh()->status);
        $shouldFail = false;
        $this->postJson($this->base()."/workflow-runs/{$failing->id}/retry")->assertOk()->assertJsonPath('data.status', 'queued');
        $this->drain();
        $this->assertSame('completed', $failing->fresh()->status);
        $this->postJson($this->base()."/workflow-runs/{$failing->id}/retry")->assertStatus(409);

        $replayId = $this->postJson($this->base()."/workflow-runs/{$failing->id}/replay", ['from_step' => 'b'])
            ->assertCreated()
            ->assertJsonPath('data.replay_of_run_id', $failing->id)
            ->assertJsonPath('data.replay_from_step', 'b')
            ->json('data.id');
        $this->drain();
        $this->assertSame('completed', WorkflowRun::find($replayId)->status);

        $this->postJson($this->base()."/workflow-runs/{$failing->id}/replay", ['from_step' => 'zz'])->assertStatus(422);
    }

    public function test_events_api(): void
    {
        $this->defineWorkflow('on-signup', fn ($step, $event) => $event->data->email, ['triggers' => [['event' => 'user.signup']]]);

        $res = $this->postJson('/api/events', ['name' => 'user.signup', 'data' => ['email' => 'a@b.c'], 'id' => 'signup-1'])->assertOk();
        $res->assertJsonCount(1, 'ids')->assertJsonCount(1, 'events.0.runs');
        $eventId = $res->json('ids.0');

        // Batch, list form and the {"events": [...]} form
        $this->postJson('/api/events', [['name' => 'a.one', 'data' => []], ['name' => 'a.two']])->assertOk()->assertJsonCount(2, 'ids');
        $this->postJson('/api/events', ['events' => [['name' => 'a.three', 'data' => ['k' => 1]]]])->assertOk()->assertJsonCount(1, 'ids');

        // Duplicate id
        $this->postJson('/api/events', ['name' => 'user.signup', 'data' => ['email' => 'a@b.c'], 'id' => 'signup-1'])
            ->assertOk()->assertJsonPath('events.0.duplicate', true)->assertJsonPath('ids.0', $eventId);

        // Test events
        $this->postJson('/api/events?test=1', ['name' => 'user.signup', 'data' => ['email' => 't@t.t']])->assertOk();
        $this->assertSame('test', WorkflowEvent::where('name', 'user.signup')->latest('created_at')->latest('id')->first()->source);

        // Validation
        $this->postJson('/api/events', ['name' => 'has space', 'data' => []])->assertStatus(422)->assertJsonPath('code', 'event_name_invalid');
        $this->postJson('/api/events', ['name' => 'runr/cron', 'data' => []])->assertStatus(422)->assertJsonPath('code', 'event_name_reserved');
        $this->postJson('/api/events', ['name' => 'x.y', 'data' => [1, 2]])->assertStatus(422)->assertJsonPath('code', 'event_data_invalid');
        $this->postJson('/api/events', [])->assertStatus(422);

        config(['workflows.limits.max_events_per_request' => 2]);
        $this->postJson('/api/events', [['name' => 'a'], ['name' => 'b'], ['name' => 'c']])->assertStatus(422)->assertJsonPath('code', 'events_too_many');

        config(['workflows.limits.max_event_bytes' => 50]);
        $this->postJson('/api/events', ['name' => 'big', 'data' => ['x' => str_repeat('z', 100)]])->assertStatus(413);

        // List + detail
        $this->getJson('/api/events?name=user.')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/events?source=test')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/events/{$eventId}")->assertOk()
            ->assertJsonPath('data.name', 'user.signup')
            ->assertJsonPath('data.external_id', 'signup-1')
            ->assertJsonPath('data.runs_started', 1)
            ->assertJsonCount(1, 'runs')
            ->assertJsonPath('runs.0.workflow.name', 'on-signup');
        $this->getJson('/api/events/01JAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound();
    }

    public function test_events_keep_empty_objects(): void
    {
        $this->call('POST', '/api/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"name":"shape.test","data":{"empty":{},"list":[]}}')->assertOk();

        $this->assertSame('{"empty":{},"list":[]}', json_encode(WorkflowEvent::where('name', 'shape.test')->sole()->data));
    }

    public function test_usage_endpoints(): void
    {
        $wf = $this->defineWorkflow('metered', function (FakeStepTools $step) {
            $step->run('one', fn () => 1);

            return 1;
        });
        $this->startRun($wf);
        $this->drain();

        $this->getJson($this->base().'/workflow-usage')->assertOk()
            ->assertJsonPath('totals.step_executions', 1)
            ->assertJsonPath('totals.runs_started', 1)
            ->assertJsonPath('by_workflow.0.workflow', 'metered')
            ->assertJsonPath('daily.0.date', now()->toDateString());

        $this->getJson('/api/workflow-usage?from='.now()->subDays(3)->toDateString())->assertOk()->assertJsonPath('totals.step_executions', 1);
        $this->getJson('/api/workflow-usage?from='.now()->addDay()->toDateString())->assertOk()->assertJsonPath('totals.step_executions', 0);
    }

    public function test_app_endpoint_override_key_rotation_and_reveal(): void
    {
        $this->patchJson($this->base().'/workflows/app', ['endpoint_url' => 'http://10.0.0.5:8080/'])
            ->assertStatus(422)->assertJsonPath('code', 'endpoint_private');
        $this->patchJson($this->base().'/workflows/app', ['endpoint_url' => 'ftp://example.com'])
            ->assertStatus(422)->assertJsonPath('code', 'endpoint_invalid');

        $this->patchJson($this->base().'/workflows/app', ['endpoint_url' => 'https://93.184.216.34/runr'])
            ->assertOk()->assertJsonPath('data.endpoint_override', 'https://93.184.216.34/runr');

        $this->patchJson($this->base().'/workflows/app', ['endpoint_url' => null])
            ->assertOk()->assertJsonPath('data.endpoint_override', null);

        $before = WorkflowApp::forProject($this->project)->fresh()->signing_key;
        $this->getJson($this->base().'/workflows/app/signing-key')->assertOk()
            ->assertJsonPath('signing_key', $before)
            ->assertJsonPath('env', 'RUNR_WORKFLOW_SIGNING_KEY');

        $rotated = $this->postJson($this->base().'/workflows/app/rotate-key')->assertOk();
        $this->assertNotNull($rotated->json('data.previous_key_expires_at'));
        $this->assertNotSame($before, WorkflowApp::forProject($this->project)->fresh()->signing_key);

        $this->getJson($this->base().'/workflows/app')->assertOk()->assertJsonPath('data.queue_bridge.enabled', false);
    }

    public function test_project_requests_accept_the_workflow_trigger(): void
    {
        foreach ([StoreProjectRequest::class, UpdateProjectRequest::class] as $class) {
            $rule = (new $class)->rules()['config.trigger_type'];

            $this->assertTrue(Validator::make(['t' => 'workflow'], ['t' => $rule])->passes(), $class);
            $this->assertTrue(Validator::make(['t' => 'queue'], ['t' => $rule])->passes(), $class);
            $this->assertFalse(Validator::make(['t' => 'event'], ['t' => $rule])->passes(), "{$class}: the unfinished event stub is gone");
        }
    }

    public function test_function_errors_are_visible_in_the_run(): void
    {
        $this->defineWorkflow('throws', fn () => throw new RuntimeException('kaboom'), ['retries' => 0]);
        $run = $this->startRun(Workflow::where('name', 'throws')->first());
        $this->drain();

        $this->getJson($this->base()."/workflow-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error.message', 'kaboom');
    }
}
