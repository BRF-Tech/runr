<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\WorkflowExecutor;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/**
 * Deterministic replay: every step body runs once, its result is memoized and sent back on
 * every later invocation; the run completes with the handler's return value.
 */
class WorkflowStepMemoizationTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    public function test_each_step_runs_once_and_results_are_memoized(): void
    {
        $workflow = $this->defineWorkflow('sum', function (FakeStepTools $step, object $event) {
            $a = $step->run('a', fn () => $event->data->x * 2);
            $b = $step->run('b', fn () => $a + 1);
            $c = $step->run('c', fn () => ['a' => $a, 'b' => $b]);

            return ['total' => $a + $b, 'c' => $c];
        });

        $run = $this->startRun($workflow, ['x' => 5]);
        $outcomes = $this->drain();

        $this->assertSame(['step_completed', 'step_completed', 'step_completed', 'run_completed'], $outcomes);
        $this->assertSame(['a' => 1, 'b' => 1, 'c' => 1], $this->fn->executions);

        $run->refresh();
        $this->assertSame(WorkflowRun::COMPLETED, $run->status);
        $this->assertEquals((object) ['total' => 21, 'c' => (object) ['a' => 10, 'b' => 11]], $run->output);
        $this->assertNotNull($run->ended_at);
        $this->assertSame(3, $run->steps_count);

        $steps = WorkflowStep::where('run_id', $run->id)->orderBy('position')->get();
        $this->assertSame(['a', 'b', 'c'], $steps->pluck('step_key')->all());
        $this->assertSame([1, 2, 3], $steps->pluck('position')->all());
        $this->assertSame([WorkflowStep::COMPLETED], $steps->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $steps[0]->attempt);
        $this->assertSame(5, $steps[0]->duration_ms);
        $this->assertSame('completed', $steps[0]->attempts[0]['status']);

        // The 4th request carried all three memoized results, in order
        $requests = $this->fn->executeRequests();
        $this->assertCount(4, $requests);
        $this->assertSame([], (array) $requests[0]->steps);
        $this->assertSame(['a', 'b', 'c'], $requests[3]->step_order);
        $this->assertSame(10, $requests[3]->steps->a->output);
        $this->assertSame('completed', $requests[3]->steps->c->status);
        $this->assertSame($run->id, $requests[3]->run->id);
        $this->assertSame('sum', $requests[3]->workflow);
        $this->assertSame(5, $requests[3]->event->data->x);
    }

    public function test_empty_objects_and_lists_survive_the_round_trip(): void
    {
        $workflow = $this->defineWorkflow('shapes', function (FakeStepTools $step) {
            $v = $step->run('shape', fn () => ['obj' => new \stdClass, 'list' => [], 'nested' => ['x' => new \stdClass]]);

            return $v;
        });

        $run = $this->startRun($workflow, []);
        $this->drain();

        $this->assertSame('{"obj":{},"list":[],"nested":{"x":{}}}', json_encode($this->step($run, 'shape')->output));
        $this->assertSame('{"obj":{},"list":[],"nested":{"x":{}}}', json_encode($run->fresh()->output));
        // The trigger event's empty data stays an object for the function
        $this->assertSame('{}', json_encode($this->fn->executeRequests()[0]->event->data));
    }

    public function test_repeated_step_ids_get_a_counter_suffix(): void
    {
        $workflow = $this->defineWorkflow('loop', function (FakeStepTools $step) {
            $sum = 0;
            foreach ([1, 2, 3] as $i) {
                $sum += $step->run('add', fn () => $i);
            }

            return $sum;
        });

        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertSame(['add', 'add:1', 'add:2'], WorkflowStep::where('run_id', $run->id)->orderBy('position')->pluck('step_key')->all());
        $this->assertSame(['add'], WorkflowStep::where('run_id', $run->id)->pluck('name')->unique()->values()->all());
        $this->assertSame(6, $run->fresh()->output);
    }

    public function test_a_duplicate_result_for_a_memoized_step_is_ignored(): void
    {
        $workflow = $this->defineWorkflow('dup', fn (FakeStepTools $step) => $step->run('a', fn () => 'first'));
        $run = $this->startRun($workflow);

        $this->assertSame('step_completed', $this->executeNext());
        $this->assertSame('first', $this->step($run, 'a')->output);

        // A function that (non-deterministically) reports `a` again does not overwrite it
        $this->fn->rawResponse = json_encode(['runr_workflow' => 'v1', 'ops' => [['op' => 'step_completed', 'id' => 'a', 'output' => 'second']]]);
        $outcome = app(WorkflowExecutor::class)->execute($run->id);

        $this->assertSame('duplicate', $outcome);
        $this->assertSame('first', $this->step($run, 'a')->output);
    }

    public function test_steps_and_runs_are_metered(): void
    {
        $workflow = $this->defineWorkflow('meter', function (FakeStepTools $step) {
            $step->run('one', fn () => 1);
            $step->run('two', fn () => 2);

            return null;
        });

        $this->startRun($workflow);
        $this->startRun($workflow);
        $this->drain();

        $usage = WorkflowUsage::where('workflow_id', $workflow->id)->firstOrFail();
        $this->assertSame(4, $usage->step_executions);
        $this->assertSame(2, $usage->runs_started);
        $this->assertSame($this->project->id, $usage->project_id);
        $this->assertSame(now()->toDateString(), $usage->date->toDateString());
    }

    public function test_execute_is_a_no_op_for_finished_or_unknown_runs(): void
    {
        $workflow = $this->defineWorkflow('noop', fn () => 'done');
        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertSame('terminal', app(WorkflowExecutor::class)->execute($run->id));
        $this->assertSame('missing', app(WorkflowExecutor::class)->execute('01JAAAAAAAAAAAAAAAAAAAAAAA'));
        $this->assertCount(1, $this->fn->executeRequests());
    }

    public function test_a_running_run_is_not_invoked_twice(): void
    {
        $workflow = $this->defineWorkflow('lease', fn (FakeStepTools $step) => $step->run('a', fn () => 1));
        $run = $this->startRun($workflow);

        $claim = app(WorkflowExecutor::class)->claim($run);
        $this->assertNotNull($claim->token);

        // A duplicate job while the lease is held does nothing
        $this->assertSame('not_queued', app(WorkflowExecutor::class)->execute($run->id));
        $this->assertCount(0, $this->fn->executeRequests());
    }
}
