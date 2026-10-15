<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\RunControl;
use App\Workflows\Engine\WorkflowException;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeNonRetriable;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/** Replay (new run, from the start or from a step) and retry in place of a failed run. */
class WorkflowReplayTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    private bool $failC = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    private function pipeline()
    {
        return $this->defineWorkflow('pipeline', function (FakeStepTools $step, object $event) {
            $a = $step->run('a', fn () => $event->data->n + 1);
            $b = $step->run('b', fn () => $a * 10);
            $c = $step->run('c', function () use ($b) {
                if ($this->failC) {
                    throw new FakeNonRetriable('c is broken');
                }

                return $b + 1;
            });

            return $c;
        });
    }

    public function test_replay_from_the_start_re_executes_every_step_in_a_new_run(): void
    {
        $workflow = $this->pipeline();
        $original = $this->startRun($workflow, ['n' => 1]);
        $this->drain();
        $this->assertSame(21, $original->fresh()->output);

        $replay = app(RunControl::class)->replay($original->fresh(), null, 'user-1');
        $this->drain();

        $replay->refresh();
        $this->assertNotSame($original->id, $replay->id);
        $this->assertSame('replay', $replay->trigger_source);
        $this->assertSame($original->id, $replay->replay_of_run_id);
        $this->assertNull($replay->replay_from_step);
        $this->assertSame('user-1', $replay->started_by);
        $this->assertSame(WorkflowRun::COMPLETED, $replay->status);
        $this->assertSame(21, $replay->output);
        $this->assertEquals($original->fresh()->event, $replay->event);
        $this->assertSame(['a' => 2, 'b' => 2, 'c' => 2], $this->fn->executions);
        $this->assertTrue($this->fn->lastRequest()->run->replay);
    }

    public function test_replay_from_a_step_keeps_the_steps_before_it(): void
    {
        $workflow = $this->pipeline();
        $original = $this->startRun($workflow, ['n' => 1]);
        $this->drain();

        $replay = app(RunControl::class)->replay($original->fresh(), 'b');

        $copied = WorkflowStep::where('run_id', $replay->id)->get();
        $this->assertSame(['a'], $copied->pluck('step_key')->all());
        $this->assertSame(2, $copied->first()->output);
        $this->assertSame(1, $replay->fresh()->steps_count);

        $this->drain();

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 2], $this->fn->executions, 'a is memoized, b and c run again');
        $this->assertSame('b', $replay->fresh()->replay_from_step);
        $this->assertSame(21, $replay->fresh()->output);
        $this->assertSame(['a', 'b', 'c'], WorkflowStep::where('run_id', $replay->id)->orderBy('position')->pluck('step_key')->all());
    }

    public function test_replay_requires_a_finished_run_and_a_known_step(): void
    {
        $workflow = $this->defineWorkflow('napper', function (FakeStepTools $step) {
            $step->run('x', fn () => 1);
            $step->sleep('s', '1h');

            return 1;
        });
        $run = $this->startRun($workflow);
        $this->drain();

        try {
            app(RunControl::class)->replay($run->fresh());
            $this->fail('an active run was replayed');
        } catch (WorkflowException $e) {
            $this->assertSame(409, $e->status);
        }

        app(RunControl::class)->cancel($run->fresh());

        try {
            app(RunControl::class)->replay($run->fresh(), 'missing-step');
            $this->fail('an unknown step was accepted');
        } catch (WorkflowException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_replay_of_a_paused_workflow_is_allowed(): void
    {
        $workflow = $this->pipeline();
        $run = $this->startRun($workflow, ['n' => 0]);
        $this->drain();

        $workflow->forceFill(['is_active' => false])->save();

        $replay = app(RunControl::class)->replay($run->fresh());
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $replay->fresh()->status);
    }

    public function test_retry_in_place_reruns_the_failed_step_and_keeps_completed_ones(): void
    {
        $this->failC = true;
        $workflow = $this->pipeline();
        $run = $this->startRun($workflow, ['n' => 1]);
        $this->drain();

        $run->refresh();
        $this->assertSame(WorkflowRun::FAILED, $run->status);
        $this->assertSame(WorkflowStep::FAILED, $this->step($run, 'c')->status);

        $this->failC = false;
        $retried = app(RunControl::class)->retry($run);

        $this->assertSame($run->id, $retried->id);
        $this->assertSame(WorkflowRun::QUEUED, $retried->status);
        $this->assertSame(1, $retried->retry_count);
        $this->assertNull($retried->error);
        $this->assertNull($this->step($run, 'c'), 'the failed step is removed');
        $this->assertSame(2, $retried->steps_count);

        $this->drain();

        $run->refresh();
        $this->assertSame(WorkflowRun::COMPLETED, $run->status);
        $this->assertSame(21, $run->output);
        $this->assertSame(['a' => 1, 'b' => 1, 'c' => 2], $this->fn->executions);
    }

    public function test_retry_is_refused_for_runs_that_did_not_fail(): void
    {
        $workflow = $this->pipeline();
        $run = $this->startRun($workflow, ['n' => 1]);
        $this->drain();

        $this->expectException(WorkflowException::class);
        app(RunControl::class)->retry($run->fresh());
    }

    public function test_retry_after_a_function_crash(): void
    {
        $crash = true;
        $workflow = $this->defineWorkflow('crash', function (FakeStepTools $step) use (&$crash) {
            $step->run('one', fn () => 1);
            if ($crash) {
                throw new RuntimeException('bug');
            }

            return 'fixed';
        }, ['retries' => 0]);

        $run = $this->startRun($workflow);
        $this->drain();
        $this->assertSame(WorkflowRun::FAILED, $this->runStatus($run));

        $crash = false;
        app(RunControl::class)->retry($run->fresh());
        $this->drain();

        $this->assertSame('fixed', $run->fresh()->output);
        $this->assertSame(1, $this->fn->executions['one']);
    }
}
