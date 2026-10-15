<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeNonRetriable;
use Tests\Support\Workflows\FakeStepFailed;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/**
 * Per-step retries with exponential backoff (base 2 s, factor 2, no jitter in tests), max
 * attempts = retries + 1, non-retriable errors, retry_after, and function-level retries.
 */
class WorkflowRetryTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->freezeSecond();
    }

    public function test_failing_step_is_retried_with_backoff_until_it_succeeds(): void
    {
        $workflow = $this->defineWorkflow('flaky', function (FakeStepTools $step) {
            return $step->run('charge', function (int $attempt) {
                if ($attempt < 2) {
                    throw new RuntimeException("gateway timeout #{$attempt}");
                }

                return ['charged' => true, 'attempt' => $attempt];
            });
        }, ['retries' => 3]);

        $run = $this->startRun($workflow);

        $this->assertSame(['step_retrying'], $this->drain());
        $step = $this->step($run, 'charge');
        $this->assertSame(WorkflowStep::RETRYING, $step->status);
        $this->assertSame(1, $step->attempt);
        $this->assertSame(4, $step->max_attempts);
        $this->assertSame('gateway timeout #0', $step->error['message']);
        $this->assertEquals(now()->addSeconds(2), $step->wake_at);
        $this->assertSame(WorkflowRun::QUEUED, $this->runStatus($run));
        $this->assertEquals(now()->addSeconds(2), $run->fresh()->next_attempt_at);

        // Not before the backoff
        $this->travel(1)->seconds();
        $this->assertSame([], $this->drain());

        $this->travel(1)->seconds();
        $this->assertSame(['step_retrying'], $this->drain());
        $step->refresh();
        $this->assertSame(2, $step->attempt);
        $this->assertEquals(now()->addSeconds(4), $step->wake_at, 'second backoff doubles');

        $this->travel(4)->seconds();
        $this->assertSame(['step_completed', 'run_completed'], $this->drain());

        $step->refresh();
        $this->assertSame(WorkflowStep::COMPLETED, $step->status);
        $this->assertSame(3, $step->attempt);
        $this->assertSame(['failed', 'failed', 'completed'], array_column($step->attempts, 'status'));
        $this->assertSame([1, 2, 3], array_column($step->attempts, 'attempt'));
        $this->assertNull($step->error);
        $this->assertEquals((object) ['charged' => true, 'attempt' => 2], $run->fresh()->output);
        $this->assertSame(3, $this->fn->executions['charge']);
    }

    public function test_exhausted_retries_fail_the_step_and_the_run(): void
    {
        $workflow = $this->defineWorkflow('broken', function (FakeStepTools $step) {
            $step->run('always', fn () => throw new RuntimeException('nope'));

            return 'unreachable';
        }, ['retries' => 1]);

        $run = $this->startRun($workflow);
        $this->drain();
        $this->travel(2)->seconds();
        $outcomes = $this->drain();

        $this->assertSame(['step_failed', 'run_failed'], $outcomes);
        $step = $this->step($run, 'always');
        $this->assertSame(WorkflowStep::FAILED, $step->status);
        $this->assertSame(2, $step->attempt);
        $this->assertSame('nope', $step->error['message']);

        $run->refresh();
        $this->assertSame(WorkflowRun::FAILED, $run->status);
        $this->assertSame('StepError', $run->error['name']);
        $this->assertStringContainsString('always', $run->error['message']);
        $this->assertNotNull($run->ended_at);
    }

    public function test_the_function_can_handle_a_failed_step(): void
    {
        $workflow = $this->defineWorkflow('compensate', function (FakeStepTools $step) {
            try {
                $step->run('reserve', fn () => throw new FakeNonRetriable('out of stock'));
            } catch (FakeStepFailed $e) {
                return $step->run('refund', fn () => 'refunded');
            }

            return 'reserved';
        });

        $run = $this->startRun($workflow);

        $this->assertSame(['step_failed', 'step_completed', 'run_completed'], $this->drain());
        $this->assertSame(1, $this->step($run, 'reserve')->attempt, 'non-retriable: one attempt');
        $this->assertSame(WorkflowStep::FAILED, $this->step($run, 'reserve')->status);
        $this->assertSame('refunded', $run->fresh()->output);
    }

    public function test_non_retriable_errors_are_not_retried(): void
    {
        $workflow = $this->defineWorkflow('fatal', function (FakeStepTools $step) {
            $step->run('validate', fn () => throw new FakeNonRetriable('invalid card'));
        }, ['retries' => 5]);

        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertSame(1, $this->fn->executions['validate']);
        $this->assertSame(WorkflowRun::FAILED, $this->runStatus($run));
    }

    public function test_retry_after_overrides_the_backoff(): void
    {
        $workflow = $this->defineWorkflow('rate-limited', function (FakeStepTools $step) {
            return $step->run('call', function (int $attempt) {
                if ($attempt === 0) {
                    throw new RuntimeException('429');
                }

                return 'ok';
            }, retryAfter: '30s');
        });

        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertEquals(now()->addSeconds(30), $this->step($run, 'call')->wake_at);
        $this->travel(29)->seconds();
        $this->assertSame([], $this->drain());
        $this->travel(1)->seconds();
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
    }

    public function test_errors_outside_steps_retry_the_function_then_fail_the_run(): void
    {
        $workflow = $this->defineWorkflow('crashy', function () {
            throw new RuntimeException('handler bug');
        }, ['retries' => 2]);

        $run = $this->startRun($workflow);

        $this->assertSame(['run_retrying'], $this->drain());
        $this->assertSame(1, $run->fresh()->attempt);
        $this->assertSame('handler bug', $run->fresh()->error['message']);

        $this->travel(2)->seconds();
        $this->assertSame(['run_retrying'], $this->drain());
        $this->assertSame(2, $run->fresh()->attempt);

        $this->travel(4)->seconds();
        $this->assertSame(['run_failed'], $this->drain());
        $this->assertSame(WorkflowRun::FAILED, $this->runStatus($run));
        $this->assertCount(3, $this->fn->executeRequests());
        $this->assertSame([0, 1, 2], array_map(fn ($r) => $r->run->attempt, $this->fn->executeRequests()));
    }

    public function test_an_unavailable_function_is_retried(): void
    {
        $workflow = $this->defineWorkflow('cold-start', fn (FakeStepTools $step) => $step->run('a', fn () => 'warm'));
        $this->fn->failNextWith = 503;
        $this->fn->failNextCount = 2;

        $run = $this->startRun($workflow);
        $this->drain();
        $this->assertSame('FunctionUnavailable', $run->fresh()->error['name']);
        $this->assertStringContainsString('HTTP 503', $run->fresh()->error['message']);
        $this->assertStringContainsString('upstream unavailable', $run->fresh()->error['message']);

        $this->travel(2)->seconds();
        $this->drain();
        $this->travel(4)->seconds();
        $this->drain();

        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
        $this->assertSame('warm', $run->fresh()->output);
        $this->assertNull($run->fresh()->error);
    }

    public function test_a_protocol_error_is_retried_then_fails(): void
    {
        $workflow = $this->defineWorkflow('garbled', fn () => 'x', ['retries' => 0]);
        $this->fn->rawResponse = '{"not":"the protocol"}';

        $run = $this->startRun($workflow);
        $this->drain();

        $run->refresh();
        $this->assertSame(WorkflowRun::FAILED, $run->status);
        $this->assertSame('ProtocolError', $run->error['name']);
    }

    public function test_step_progress_resets_the_function_attempt_counter(): void
    {
        $calls = 0;
        $workflow = $this->defineWorkflow('progress', function (FakeStepTools $step) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('transient');
            }
            $step->run('a', fn () => 1);

            return 'ok';
        }, ['retries' => 1]);

        $run = $this->startRun($workflow);
        $this->drain();
        $this->assertSame(1, $run->fresh()->attempt);

        $this->travel(2)->seconds();
        $this->drain();

        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
        $this->assertSame(0, $run->fresh()->attempt);
    }
}
