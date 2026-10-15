<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\RunControl;
use App\Workflows\Engine\Ticker;
use App\Workflows\Engine\WorkflowExecutor;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/**
 * Concurrency limits count runs that are executing (`running`); sleeping / waiting runs hold no
 * slot. A run leaving `running` hands its slot to the oldest queued run.
 */
class WorkflowConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->freezeSecond();
    }

    public function test_a_full_workflow_defers_new_runs_until_a_slot_frees(): void
    {
        $workflow = $this->defineWorkflow('serial', fn (FakeStepTools $step) => $step->run('only', fn () => 'ok'), ['concurrency' => 1]);

        $a = $this->startRun($workflow);
        $b = $this->startRun($workflow);
        $this->dispatcher->clear();

        // A is executing (holds the lease)
        $this->assertNotNull(app(WorkflowExecutor::class)->claim($a)->token);
        $this->assertSame(WorkflowRun::RUNNING, $this->runStatus($a));

        $this->assertSame('concurrency_limited', app(WorkflowExecutor::class)->execute($b->id));
        $this->assertSame(WorkflowRun::QUEUED, $this->runStatus($b));
        $this->assertSame([], $this->dispatcher->queue, 'a deferred run does not poll');

        // A ends → B is dispatched and runs
        app(RunControl::class)->cancel($a->fresh());
        $this->assertSame([$b->id], array_column($this->dispatcher->queue, 0));

        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($b));
    }

    public function test_sleeping_runs_do_not_hold_a_slot(): void
    {
        $workflow = $this->defineWorkflow('sleepy', function (FakeStepTools $step) {
            $step->sleep('nap', '1h');

            return 'done';
        }, ['concurrency' => 1]);

        $a = $this->startRun($workflow);
        $b = $this->startRun($workflow);
        $this->drain();

        $this->assertSame(WorkflowRun::SLEEPING, $this->runStatus($a));
        $this->assertSame(WorkflowRun::SLEEPING, $this->runStatus($b));

        $this->advance(3600);
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($a));
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($b));
    }

    public function test_keyed_limit_applies_per_key(): void
    {
        $workflow = $this->defineWorkflow('per-customer', fn (FakeStepTools $step) => $step->run('s', fn () => 1), [
            'concurrency' => ['limit' => 1, 'key' => 'event.data.customer'],
        ]);

        $x1 = $this->startRun($workflow, ['customer' => 'x']);
        $y1 = $this->startRun($workflow, ['customer' => 'y']);
        $x2 = $this->startRun($workflow, ['customer' => 'x']);
        $this->dispatcher->clear();

        $this->assertSame('ck:x', $x1->fresh()->concurrency_key);
        $this->assertNotNull(app(WorkflowExecutor::class)->claim($x1)->token);

        $this->assertNotNull(app(WorkflowExecutor::class)->claim($y1)->token, 'another key has its own slot');
        $this->assertNull(app(WorkflowExecutor::class)->claim($x2)->token);
        $this->assertSame('concurrency_limited', app(WorkflowExecutor::class)->claim($x2)->reason);
    }

    public function test_workflow_wide_and_keyed_limits_combine(): void
    {
        $workflow = $this->defineWorkflow('both', fn () => 1, [
            'concurrency' => [['limit' => 2], ['limit' => 1, 'key' => 'event.data.k']],
        ]);

        $runs = [];
        foreach (['a', 'b', 'c'] as $k) {
            $runs[$k] = $this->startRun($workflow, ['k' => $k]);
        }

        $this->assertNotNull(app(WorkflowExecutor::class)->claim($runs['a'])->token);
        $this->assertNotNull(app(WorkflowExecutor::class)->claim($runs['b'])->token);
        $this->assertNull(app(WorkflowExecutor::class)->claim($runs['c'])->token, 'workflow-wide limit 2 reached');
    }

    public function test_tick_redispatches_queued_runs_left_behind(): void
    {
        $workflow = $this->defineWorkflow('lost', fn () => 'ok', ['concurrency' => 1]);
        $run = $this->startRun($workflow);
        $this->dispatcher->clear(); // the job was lost

        $this->travel(3)->minutes();
        $stats = app(Ticker::class)->tick();

        $this->assertSame(1, $stats['redispatched']);
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
    }

    public function test_expired_lease_is_recovered_as_a_failed_attempt(): void
    {
        $workflow = $this->defineWorkflow('crashed-worker', fn () => 'ok');
        $run = $this->startRun($workflow);
        $this->dispatcher->clear();

        app(WorkflowExecutor::class)->claim($run); // worker took it, then died
        $this->travel(WorkflowExecutor::leaseSeconds() + 1)->seconds();

        $stats = app(Ticker::class)->tick();
        $this->assertSame(1, $stats['recovered']);

        $run->refresh();
        $this->assertSame(WorkflowRun::QUEUED, $run->status);
        $this->assertSame('WorkerLost', $run->error['name']);
        $this->assertSame(1, $run->attempt);

        $this->travel(2)->seconds();
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
    }
}
