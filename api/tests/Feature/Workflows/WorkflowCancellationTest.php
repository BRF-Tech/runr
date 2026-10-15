<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\EventIngestor;
use App\Workflows\Engine\RunControl;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/** Cancellation: API / control, in-flight results discarded, cancel_on events, run timeouts. */
class WorkflowCancellationTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->freezeSecond();
    }

    public function test_cancelling_a_sleeping_run_cancels_its_open_steps(): void
    {
        $workflow = $this->defineWorkflow('nap', function (FakeStepTools $step) {
            $step->run('a', fn () => 1);
            $step->sleep('zzz', '1h');

            return $step->run('b', fn () => 2);
        });

        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertTrue(app(RunControl::class)->cancel($run, 'no longer needed'));

        $run->refresh();
        $this->assertSame(WorkflowRun::CANCELLED, $run->status);
        $this->assertSame(['name' => 'Cancelled', 'message' => 'no longer needed'], $run->error);
        $this->assertNotNull($run->ended_at);
        $this->assertSame(WorkflowStep::CANCELLED, $this->step($run, 'zzz')->status);
        $this->assertSame(WorkflowStep::COMPLETED, $this->step($run, 'a')->status);

        // Waking up later does nothing
        $this->advance(7200);
        $this->assertArrayNotHasKey('b', $this->fn->executions);
        $this->assertSame(WorkflowRun::CANCELLED, $this->runStatus($run));

        // A finished run cannot be cancelled again
        $this->assertFalse(app(RunControl::class)->cancel($run->fresh()));
    }

    public function test_a_result_arriving_after_cancellation_is_discarded(): void
    {
        $runId = null;
        $workflow = $this->defineWorkflow('slow', function (FakeStepTools $step) use (&$runId) {
            return $step->run('long-call', function () use (&$runId) {
                // Cancelled while the function is still executing the step
                app(RunControl::class)->cancel(WorkflowRun::findOrFail($runId), 'user cancelled');

                return 'too late';
            });
        });

        $run = $this->startRun($workflow);
        $runId = $run->id;

        $this->assertSame(['lease_lost'], $this->drain());
        $this->assertSame(WorkflowRun::CANCELLED, $this->runStatus($run));
        $this->assertNull($this->step($run, 'long-call'), 'the late step result was not stored');
    }

    public function test_cancel_on_event_cancels_matching_runs_only(): void
    {
        $workflow = $this->defineWorkflow('fulfil', function (FakeStepTools $step) {
            $step->waitForEvent('shipped', 'order.shipped', match: 'data.order_id', timeout: '7d');

            return 'done';
        }, ['cancel_on' => [['event' => 'order.cancelled', 'match' => 'data.order_id']]]);

        $a = $this->startRun($workflow, ['order_id' => 1]);
        $b = $this->startRun($workflow, ['order_id' => 2]);
        $this->drain();

        $result = app(EventIngestor::class)->ingest([['name' => 'order.cancelled', 'data' => ['order_id' => 1]]])[0];

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(WorkflowRun::CANCELLED, $this->runStatus($a));
        $this->assertStringContainsString('order.cancelled', $a->fresh()->error['message']);
        $this->assertSame(WorkflowRun::WAITING, $this->runStatus($b));
    }

    public function test_cancel_on_without_match_cancels_all_active_runs_of_the_workflow(): void
    {
        $workflow = $this->defineWorkflow('campaign', function (FakeStepTools $step) {
            $step->sleep('drip', '1d');

            return 1;
        }, ['cancel_on' => [['event' => 'campaign.stopped']]]);

        $runs = [$this->startRun($workflow), $this->startRun($workflow)];
        $this->drain();

        app(EventIngestor::class)->ingest([['name' => 'campaign.stopped', 'data' => []]]);

        foreach ($runs as $run) {
            $this->assertSame(WorkflowRun::CANCELLED, $this->runStatus($run));
        }
    }

    public function test_run_timeout_marks_the_run_timed_out(): void
    {
        $workflow = $this->defineWorkflow('bounded', function (FakeStepTools $step) {
            $step->sleep('forever', '10d');

            return 1;
        }, ['timeout' => '1h']);

        $run = $this->startRun($workflow);
        $this->drain();

        $stats = $this->advance(3600);
        $this->assertSame(1, $stats['timed_out']);

        $run->refresh();
        $this->assertSame(WorkflowRun::TIMED_OUT, $run->status);
        $this->assertSame('RunTimeout', $run->error['name']);
        $this->assertSame(WorkflowStep::CANCELLED, $this->step($run, 'forever')->status);
    }

    public function test_execute_times_out_an_overdue_run(): void
    {
        $workflow = $this->defineWorkflow('late', fn () => 'x', ['timeout' => '1m']);
        $run = $this->startRun($workflow);

        $this->travel(2)->minutes();

        $this->assertSame(['timed_out'], $this->drain());
        $this->assertSame(WorkflowRun::TIMED_OUT, $this->runStatus($run));
        $this->assertCount(0, $this->fn->executeRequests());
    }
}
