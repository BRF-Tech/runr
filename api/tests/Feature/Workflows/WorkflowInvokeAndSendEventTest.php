<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\RunControl;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeNonRetriable;
use Tests\Support\Workflows\FakeStepFailed;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/** step.invoke (child runs) and step.sendEvent (events emitted by a run). */
class WorkflowInvokeAndSendEventTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->freezeSecond();
    }

    public function test_invoke_runs_the_child_and_returns_its_output(): void
    {
        $this->defineWorkflow('double', fn (FakeStepTools $step, object $event) => $step->run('calc', fn () => $event->data->n * 2));
        $parent = $this->defineWorkflow('parent', function (FakeStepTools $step) {
            $r = $step->invoke('child', 'double', ['n' => 21]);

            return ['child' => $r];
        });

        $run = $this->startRun($parent);
        $this->drain();

        $run->refresh();
        $this->assertSame(WorkflowRun::COMPLETED, $run->status);
        $this->assertEquals((object) ['child' => 42], $run->output);

        $step = $this->step($run, 'child');
        $child = WorkflowRun::findOrFail($step->child_run_id);
        $this->assertSame(WorkflowStep::TYPE_INVOKE, $step->type);
        $this->assertSame(WorkflowStep::COMPLETED, $step->status);
        $this->assertSame('invoke', $child->trigger_source);
        $this->assertSame($run->id, $child->parent_run_id);
        $this->assertSame($step->id, $child->parent_step_id);
        $this->assertSame('runr/invoke', $child->event->name);
        $this->assertSame(21, $child->event->data->n);
        $this->assertSame('invoke:'.$step->id, $child->idempotency_key);
        $childRequest = collect($this->fn->executeRequests())->firstWhere('workflow', 'double');
        $this->assertSame($run->id, $childRequest->run->parent_run_id);
    }

    public function test_a_failed_child_fails_the_invoke_step(): void
    {
        $this->defineWorkflow('fragile', fn (FakeStepTools $step) => $step->run('x', fn () => throw new FakeNonRetriable('child broke')));
        $parent = $this->defineWorkflow('caller', function (FakeStepTools $step) {
            try {
                $step->invoke('call', 'fragile');
            } catch (FakeStepFailed $e) {
                return 'handled: '.$e->getMessage();
            }

            return 'ok';
        });

        $run = $this->startRun($parent);
        $this->drain();

        $step = $this->step($run, 'call');
        $this->assertSame(WorkflowStep::FAILED, $step->status);
        $this->assertSame('InvokeFailed', $step->error['name']);
        $this->assertStringContainsString('failed', $step->error['message']);
        $this->assertStringStartsWith('handled:', $run->fresh()->output);
    }

    public function test_invoke_timeout_fails_the_step_and_cancels_the_child(): void
    {
        $this->defineWorkflow('slowpoke', function (FakeStepTools $step) {
            $step->sleep('long', '1d');

            return 1;
        });
        $parent = $this->defineWorkflow('impatient', function (FakeStepTools $step) {
            try {
                return $step->invoke('wait-child', 'slowpoke', timeout: '1h');
            } catch (FakeStepFailed $e) {
                return 'gave up';
            }
        });

        $run = $this->startRun($parent);
        $this->drain();
        $this->assertSame(WorkflowRun::WAITING, $this->runStatus($run));

        $this->advance(3600);

        $step = $this->step($run, 'wait-child');
        $this->assertSame(WorkflowStep::FAILED, $step->status);
        $this->assertSame('InvokeTimeout', $step->error['name']);
        $this->assertSame(WorkflowRun::CANCELLED, WorkflowRun::findOrFail($step->child_run_id)->status);
        $this->assertSame('gave up', $run->fresh()->output);
    }

    public function test_invoking_an_unknown_workflow_fails_the_step(): void
    {
        $parent = $this->defineWorkflow('lost', function (FakeStepTools $step) {
            try {
                $step->invoke('x', 'does-not-exist');
            } catch (FakeStepFailed $e) {
                return 'missing';
            }
        });

        $run = $this->startRun($parent);
        $this->drain();

        $this->assertSame('WorkflowNotFound', $this->step($run, 'x')->error['name']);
        $this->assertSame('missing', $run->fresh()->output);
    }

    public function test_cancelling_the_parent_cancels_the_child(): void
    {
        $this->defineWorkflow('sleeper', function (FakeStepTools $step) {
            $step->sleep('z', '1d');

            return 1;
        });
        $parent = $this->defineWorkflow('boss', fn (FakeStepTools $step) => $step->invoke('c', 'sleeper'));

        $run = $this->startRun($parent);
        $this->drain();
        $child = WorkflowRun::findOrFail($this->step($run, 'c')->child_run_id);

        app(RunControl::class)->cancel($run->fresh());

        $this->assertSame(WorkflowRun::CANCELLED, $child->fresh()->status);
    }

    public function test_send_event_starts_triggered_workflows_and_returns_ids(): void
    {
        $this->defineWorkflow('notify', fn ($step, $event) => 'notified '.$event->data->order, [
            'triggers' => [['event' => 'order.done']],
        ]);
        $emitter = $this->defineWorkflow('emitter', function (FakeStepTools $step) {
            $sent = $step->sendEvent('announce', [['name' => 'order.done', 'data' => ['order' => 'o-9']]]);

            return $sent;
        });

        $run = $this->startRun($emitter);
        $this->drain();

        $step = $this->step($run, 'announce');
        $this->assertSame(WorkflowStep::TYPE_SEND_EVENT, $step->type);
        $this->assertSame(WorkflowStep::COMPLETED, $step->status);

        $event = WorkflowEvent::where('name', 'order.done')->sole();
        $this->assertSame([$event->id], $step->output->ids);
        $this->assertSame('workflow', $event->source);
        $this->assertSame('run:'.$run->id, $event->sent_by);
        $this->assertSame($this->project->id, $event->project_id);

        $notify = WorkflowRun::where('event_id', $event->id)->sole();
        $this->assertSame('event', $notify->trigger_source);
        $this->assertSame('notified o-9', $notify->output);
    }

    public function test_send_event_with_an_invalid_event_fails_the_step(): void
    {
        $emitter = $this->defineWorkflow('bad-emitter', function (FakeStepTools $step) {
            try {
                $step->sendEvent('oops', [['name' => 'runr/reserved', 'data' => []]]);
            } catch (FakeStepFailed $e) {
                return 'refused';
            }
        });

        $run = $this->startRun($emitter);
        $this->drain();

        $this->assertSame('SendEventFailed', $this->step($run, 'oops')->error['name']);
        $this->assertSame('refused', $run->fresh()->output);
    }

    public function test_a_run_cancelled_by_its_own_event_stops(): void
    {
        $workflow = $this->defineWorkflow('self-stop', function (FakeStepTools $step) {
            $step->sendEvent('stop', [['name' => 'job.stop', 'data' => []]]);

            return $step->run('after', fn () => 'should not run');
        }, ['cancel_on' => [['event' => 'job.stop']]]);

        $run = $this->startRun($workflow);
        $outcomes = $this->drain();

        $this->assertContains('cancelled_by_event', $outcomes);
        $this->assertSame(WorkflowRun::CANCELLED, $this->runStatus($run));
        $this->assertArrayNotHasKey('after', $this->fn->executions);
    }
}
