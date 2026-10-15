<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Dispatch\QueueWorkflowDispatcher;
use App\Workflows\Engine\Ticker;
use App\Workflows\Jobs\ExecuteWorkflowRunJob;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/** step.sleep / step.sleepUntil: the run parks, frees its slot and resumes when due. */
class WorkflowSleepTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->freezeSecond();
    }

    public function test_sleep_parks_the_run_and_resumes_when_due(): void
    {
        $workflow = $this->defineWorkflow('reminder', function (FakeStepTools $step) {
            $step->run('send-first', fn () => 'sent');
            $step->sleep('wait-a-day', '1d');

            return $step->run('send-second', fn () => 'sent again');
        });

        $run = $this->startRun($workflow);
        $this->assertSame(['step_completed', 'sleeping'], $this->drain());

        $run->refresh();
        $this->assertSame(WorkflowRun::SLEEPING, $run->status);
        $this->assertEquals(now()->addDay(), $run->next_attempt_at);
        $this->assertNull($run->lease_token);

        $sleep = $this->step($run, 'wait-a-day');
        $this->assertSame(WorkflowStep::TYPE_SLEEP, $sleep->type);
        $this->assertSame(WorkflowStep::SLEEPING, $sleep->status);
        $this->assertEquals(now()->addDay(), $sleep->wake_at);
        $this->assertSame('1d', $sleep->input->duration);

        // Ticks before the wake-up change nothing
        $stats = $this->advance(3600);
        $this->assertSame(0, $stats['woken']);
        $this->assertSame(WorkflowRun::SLEEPING, $this->runStatus($run));
        $this->assertArrayNotHasKey('send-second', $this->fn->executions);

        $stats = $this->advance(86400);
        $this->assertSame(1, $stats['woken']);

        $run->refresh();
        $this->assertSame(WorkflowRun::COMPLETED, $run->status);
        $this->assertSame('sent again', $run->output);
        $this->assertSame(WorkflowStep::COMPLETED, $this->step($run, 'wait-a-day')->status);
        $this->assertSame(1, $this->fn->executions['send-first'], 'steps before the sleep are not re-run');
    }

    public function test_a_due_delayed_job_wakes_the_run_without_a_tick(): void
    {
        $workflow = $this->defineWorkflow('short-nap', function (FakeStepTools $step) {
            $step->sleep('nap', '10s');

            return 'awake';
        });

        $run = $this->startRun($workflow);
        $this->drain();
        $this->assertSame(WorkflowRun::SLEEPING, $this->runStatus($run));

        $this->travel(5)->seconds();
        $this->assertSame([], $this->drain(), 'not due yet');

        $this->travel(5)->seconds();
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
    }

    public function test_sleep_until_in_the_past_continues_immediately(): void
    {
        $workflow = $this->defineWorkflow('past', function (FakeStepTools $step) {
            $step->sleepUntil('already', now()->subMinute()->toIso8601String());

            return 'done';
        });

        $run = $this->startRun($workflow);

        $this->assertSame(['sleep_elapsed', 'run_completed'], $this->drain());
        $this->assertSame(WorkflowStep::COMPLETED, $this->step($run, 'already')->status);
    }

    public function test_sleep_until_an_instant(): void
    {
        $until = now()->addHours(3)->startOfSecond();
        $workflow = $this->defineWorkflow('until', function (FakeStepTools $step) use ($until) {
            $step->sleepUntil('later', $until->toIso8601ZuluString());

            return 'done';
        });

        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertEquals($until, $this->step($run, 'later')->wake_at);
        $this->advance(3 * 3600);
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
    }

    public function test_an_invalid_sleep_fails_the_run(): void
    {
        $workflow = $this->defineWorkflow('bad-sleep', function (FakeStepTools $step) {
            $step->sleep('x', 'whenever');
        });

        $run = $this->startRun($workflow);
        $this->drain();

        $run->refresh();
        $this->assertSame(WorkflowRun::FAILED, $run->status);
        $this->assertSame('ProtocolError', $run->error['name']);
    }

    public function test_queue_dispatcher_delays_short_waits_and_leaves_long_ones_to_the_tick(): void
    {
        Queue::fake();
        $dispatcher = new QueueWorkflowDispatcher;

        $dispatcher->dispatch('01JRUNAAAAAAAAAAAAAAAAAAAA');
        $dispatcher->dispatch('01JRUNBBBBBBBBBBBBBBBBBBBB', now()->addMinutes(5));
        $dispatcher->dispatch('01JRUNCCCCCCCCCCCCCCCCCCCC', now()->addHours(2));

        Queue::assertPushed(ExecuteWorkflowRunJob::class, 2);
        Queue::assertPushed(ExecuteWorkflowRunJob::class, fn ($job) => $job->runId === '01JRUNAAAAAAAAAAAAAAAAAAAA' && $job->delay === null);
        Queue::assertPushed(ExecuteWorkflowRunJob::class, fn ($job) => $job->runId === '01JRUNBBBBBBBBBBBBBBBBBBBB' && $job->delay === 300);
        Queue::assertNotPushed(ExecuteWorkflowRunJob::class, fn ($job) => $job->runId === '01JRUNCCCCCCCCCCCCCCCCCCCC');
    }

    public function test_tick_wakes_long_sleeps(): void
    {
        $workflow = $this->defineWorkflow('long', function (FakeStepTools $step) {
            $step->sleep('week', '7d');

            return 'ok';
        });

        $run = $this->startRun($workflow);
        $this->drain();
        $this->dispatcher->clear(); // as if the far-future delayed job never existed

        $this->travel(7)->days();
        $stats = app(Ticker::class)->tick();
        $this->assertSame(1, $stats['woken']);
        $this->drain();

        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
    }
}
