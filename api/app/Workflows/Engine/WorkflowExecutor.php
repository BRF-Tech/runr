<?php

namespace App\Workflows\Engine;

use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowUsage;
use App\Workflows\Transport\TransportException;
use App\Workflows\Transport\WorkflowTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Drives a run one invocation at a time (docs/workflows.md § Execution model):
 *
 *   1. claim  — queued → running under a lease token, if the run is due and its workflow's
 *               concurrency limits leave a slot;
 *   2. invoke — POST the signed execute request (trigger event + memoized steps) to the
 *               project's function;
 *   3. apply  — persist the single op the SDK answers (step completed / failed, sleep, wait,
 *               invoke, send event, run completed / failed) if the lease still holds, and
 *               schedule what comes next.
 *
 * Steps execute at least once: when a result is lost (worker crash, lease expiry) the step runs
 * again on the next invocation.
 */
class WorkflowExecutor
{
    public function __construct(
        private readonly WorkflowTransport $transport,
        private readonly WorkflowDispatcher $dispatcher,
        private readonly RunControl $control,
        private readonly RunStarter $starter,
        private readonly EventIngestor $events,
        private readonly EndpointResolver $endpoints,
    ) {}

    /** One execution of a run; returns what happened (logs, tests). */
    public function execute(string $runId): string
    {
        $run = WorkflowRun::with('workflow.project')->find($runId);
        if (! $run || ! $run->workflow) {
            return 'missing';
        }
        if ($run->isTerminal()) {
            return 'terminal';
        }

        if ($run->timeout_at && ! $run->timeout_at->isFuture()) {
            $this->control->timeOut($run);

            return 'timed_out';
        }

        if (in_array($run->status, [WorkflowRun::SLEEPING, WorkflowRun::WAITING], true)) {
            if ($run->next_attempt_at && $run->next_attempt_at->isFuture()) {
                return 'not_due';
            }
            $woken = $run->status === WorkflowRun::SLEEPING
                ? $this->control->wakeDueSleeps($run, false)
                : $this->control->expireWaits($run, false);
            if (! $woken) {
                return 'not_due';
            }
            $run->refresh();
        }

        $claim = $this->claim($run);
        if ($claim->token === null) {
            if ($claim->retryAt) {
                $this->dispatcher->dispatch($run->id, $claim->retryAt);
            }

            return $claim->reason;
        }

        $run->refresh();

        return $this->invoke($run, $claim->token);
    }

    // ── 1. Claim ───────────────────────────────────────────────────────────

    public function claim(WorkflowRun $run): Claim
    {
        return DB::transaction(function () use ($run) {
            $workflow = Workflow::whereKey($run->workflow_id)->lockForUpdate()->first();
            $fresh = WorkflowRun::whereKey($run->id)->lockForUpdate()->first();

            if (! $workflow || ! $fresh || $fresh->status !== WorkflowRun::QUEUED) {
                return Claim::none();
            }

            if ($fresh->next_attempt_at && $fresh->next_attempt_at->isFuture()) {
                return Claim::deferred($fresh->next_attempt_at, 'not_due');
            }

            foreach ($workflow->concurrencyLimits() as $limit) {
                $running = WorkflowRun::where('workflow_id', $workflow->id)->where('status', WorkflowRun::RUNNING);
                if (! empty($limit['key'])) {
                    if ($fresh->concurrency_key === null) {
                        continue;
                    }
                    $running->where('concurrency_key', $fresh->concurrency_key);
                }
                if ($running->count() >= (int) $limit['limit']) {
                    // No polling: a run leaving `running` hands its slot on (RunControl::releaseSlot),
                    // workflows:tick re-dispatches queued runs as a safety net
                    return Claim::deferred(null, 'concurrency_limited');
                }
            }

            $token = Str::random(32);
            $fresh->forceFill([
                'status' => WorkflowRun::RUNNING,
                'lease_token' => $token,
                'lease_until' => now()->addSeconds(self::leaseSeconds()),
                'started_at' => $fresh->started_at ?? now(),
            ])->save();

            return Claim::claimed($token);
        });
    }

    public static function leaseSeconds(): int
    {
        return (int) config('workflows.request_timeout', 300) + 60;
    }

    // ── 2. Invoke ──────────────────────────────────────────────────────────

    private function invoke(WorkflowRun $run, string $token): string
    {
        $workflow = $run->workflow;
        $project = $workflow->project;

        if (! $project) {
            return $this->runError($run, $token, ['name' => 'ProjectMissing', 'message' => 'The project of this workflow no longer exists'], false);
        }

        $app = WorkflowApp::forProject($project);
        $url = $this->endpoints->resolve($project, $app);
        if ($url === null) {
            return $this->runError($run, $token, ['name' => 'EndpointUnavailable', 'message' => __('workflows.endpoint_unknown')], true);
        }

        $steps = WorkflowStep::where('run_id', $run->id)->orderBy('position')->get();

        try {
            $envelope = Protocol::envelope($this->buildRequest($run, $workflow, $steps), $app->signingKeys());
        } catch (\JsonException $e) {
            return $this->runError($run, $token, ['name' => 'StateEncodingError', 'message' => $e->getMessage()], false);
        }

        $maxRequest = (int) config('workflows.limits.max_request_bytes', 8388608);
        if (strlen($envelope['body']) > $maxRequest) {
            return $this->runError($run, $token, ['name' => 'StateTooLarge', 'message' => __('workflows.state_too_large', ['max' => $maxRequest])], false);
        }

        try {
            $response = $this->transport->send($url, $envelope['body'], $envelope['headers']);
            $ops = Protocol::parseOps($response->body);
        } catch (TransportException $e) {
            return $this->runError($run, $token, $e->toError(), true);
        } catch (ProtocolException $e) {
            return $this->runError($run, $token, ['name' => 'ProtocolError', 'message' => $e->getMessage()], true);
        }

        if (count($ops) > 1) {
            Log::warning('Workflow function answered more than one op; protocol v1 applies the first', ['run' => $run->id, 'ops' => count($ops)]);
        }

        return $this->apply($run, $token, $ops[0], $response->durationMs);
    }

    /** The execute request for the current state of a run. */
    public function buildRequest(WorkflowRun $run, Workflow $workflow, Collection $steps): array
    {
        $memo = [];
        $order = [];
        $attempt = (int) $run->attempt;

        foreach ($steps as $step) {
            if ($step->status === WorkflowStep::COMPLETED) {
                $memo[$step->step_key] = ['status' => 'completed', 'output' => $step->output];
                $order[] = $step->step_key;
            } elseif ($step->status === WorkflowStep::FAILED) {
                $memo[$step->step_key] = ['status' => 'failed', 'error' => $step->error ?? ['name' => 'Error', 'message' => '']];
                $order[] = $step->step_key;
            } elseif ($step->status === WorkflowStep::RETRYING) {
                // the step about to run again: ctx.attempt = attempts made so far
                $attempt = max($attempt, (int) $step->attempt);
            }
        }

        return [
            'type' => Protocol::TYPE_EXECUTE,
            'protocol' => Protocol::VERSION,
            'ts' => time(),
            'workflow' => $workflow->name,
            'run' => [
                'id' => $run->id,
                'attempt' => $attempt,
                'started_at' => $run->started_at?->toIso8601ZuluString('millisecond'),
                'source' => $run->trigger_source,
                'replay' => $run->replay_of_run_id !== null,
                'parent_run_id' => $run->parent_run_id,
            ],
            'event' => $run->event ?? new \stdClass,
            'steps' => (object) $memo,
            'step_order' => $order,
        ];
    }

    // ── 3. Apply ───────────────────────────────────────────────────────────

    private function apply(WorkflowRun $run, string $token, array $op, int $durationMs): string
    {
        return DB::transaction(function () use ($run, $token, $op, $durationMs) {
            $fresh = WorkflowRun::with('workflow')->lockForUpdate()->find($run->id);

            // Cancelled, timed out or reclaimed while the function ran: the result is discarded
            if (! $fresh || $fresh->status !== WorkflowRun::RUNNING || $fresh->lease_token !== $token) {
                return 'lease_lost';
            }

            return match ($op['op']) {
                Protocol::OP_STEP_COMPLETED => $this->onStepCompleted($fresh, $op, $durationMs),
                Protocol::OP_STEP_FAILED => $this->onStepFailed($fresh, $op, $durationMs),
                Protocol::OP_SLEEP => $this->onSleep($fresh, $op),
                Protocol::OP_WAIT_FOR_EVENT => $this->onWaitForEvent($fresh, $op),
                Protocol::OP_INVOKE => $this->onInvoke($fresh, $op),
                Protocol::OP_SEND_EVENT => $this->onSendEvent($fresh, $op),
                Protocol::OP_RUN_COMPLETED => $this->onRunCompleted($fresh, $op),
                Protocol::OP_RUN_FAILED => $this->failOrRetryRun($fresh, $op['error'], ($op['retriable'] ?? true) !== false),
                // Protocol::parseOps() accepts the operations above only
                default => throw new \UnexpectedValueException('Unknown workflow operation'),
            };
        });
    }

    private function onStepCompleted(WorkflowRun $run, array $op, int $durationMs): string
    {
        [$step, $new] = $this->stepFor($run, $op, WorkflowStep::TYPE_RUN);
        if ($step === null) {
            return $this->tooManySteps($run);
        }
        if (! $new && $step->isMemoized()) {
            return $this->requeue($run, null, 'duplicate');
        }

        $output = $op['output'] ?? null;
        $max = (int) config('workflows.limits.max_step_output_bytes', 1048576);
        $taken = $this->opDuration($op, $durationMs);

        if (Protocol::size($output) > $max) {
            $step->attempt = $step->attempt + 1;
            $this->meter($run);

            return $this->failStep($run, $step, $new, ['name' => 'OutputTooLarge', 'message' => __('workflows.step_output_too_large', ['max' => $max])], $taken);
        }

        $attempt = $step->attempt + 1;
        $this->recordAttempt($step, $attempt, 'completed', $taken);
        $step->fill([
            'status' => WorkflowStep::COMPLETED,
            'attempt' => $attempt,
            'output' => $output,
            'error' => null,
            'wake_at' => null,
            'ended_at' => now(),
            'duration_ms' => $taken,
        ]);
        $this->saveStep($run, $step, $new);
        $this->meter($run);

        return $this->requeue($run, null, 'step_completed');
    }

    private function onStepFailed(WorkflowRun $run, array $op, int $durationMs): string
    {
        [$step, $new] = $this->stepFor($run, $op, WorkflowStep::TYPE_RUN);
        if ($step === null) {
            return $this->tooManySteps($run);
        }
        if (! $new && $step->isMemoized()) {
            return $this->requeue($run, null, 'duplicate');
        }

        $attempt = $step->attempt + 1;
        $retriable = ($op['retriable'] ?? true) !== false;
        $taken = $this->opDuration($op, $durationMs);

        $this->recordAttempt($step, $attempt, 'failed', $taken, $op['error']);
        $this->meter($run);

        if ($retriable && $attempt < $step->max_attempts) {
            $wake = now()->addMilliseconds($this->retryDelayMs($op, $attempt));
            $step->fill([
                'status' => WorkflowStep::RETRYING,
                'attempt' => $attempt,
                'error' => $op['error'],
                'wake_at' => $wake,
                'duration_ms' => $taken,
            ]);
            $this->saveStep($run, $step, $new);
            $this->control->releaseSlot($run);

            return $this->requeue($run, $wake, 'step_retrying');
        }

        $step->fill(['attempt' => $attempt]);

        return $this->failStep($run, $step, $new, $op['error'], $taken, false);
    }

    private function onSleep(WorkflowRun $run, array $op): string
    {
        [$step, $new] = $this->stepFor($run, $op, WorkflowStep::TYPE_SLEEP);
        if ($step === null) {
            return $this->tooManySteps($run);
        }
        if (! $new && $step->isMemoized()) {
            return $this->requeue($run, null, 'duplicate');
        }

        try {
            $until = isset($op['until']) ? Duration::parseInstant($op['until']) : Duration::from($op['duration']);
        } catch (InvalidArgumentException $e) {
            return $this->failOrRetryRun($run, ['name' => 'ProtocolError', 'message' => 'sleep: '.$e->getMessage()], false);
        }

        $this->meter($run);
        $step->fill([
            'attempt' => $step->attempt + 1,
            'input' => ['until' => $until->toIso8601ZuluString('millisecond'), 'duration' => $op['duration'] ?? null],
            'wake_at' => $until,
        ]);

        if (! $until->isFuture()) {
            $step->fill(['status' => WorkflowStep::COMPLETED, 'ended_at' => now(), 'duration_ms' => 0]);
            $this->saveStep($run, $step, $new);

            return $this->requeue($run, null, 'sleep_elapsed');
        }

        $step->fill(['status' => WorkflowStep::SLEEPING]);
        $this->saveStep($run, $step, $new);

        return $this->park($run, WorkflowRun::SLEEPING, $until, 'sleeping');
    }

    private function onWaitForEvent(WorkflowRun $run, array $op): string
    {
        [$step, $new] = $this->stepFor($run, $op, WorkflowStep::TYPE_WAIT);
        if ($step === null) {
            return $this->tooManySteps($run);
        }
        if (! $new && $step->isMemoized()) {
            return $this->requeue($run, null, 'duplicate');
        }

        try {
            if (! preg_match(Definition::EVENT_PATTERN, $op['event'])) {
                throw new InvalidArgumentException('invalid event name');
            }
            $expression = Expression::combine($this->stringOrNull($op['match'] ?? null), $this->stringOrNull($op['if'] ?? null));
            $timeoutAt = $this->timeoutAt($op['timeout'] ?? null, $run);
        } catch (InvalidArgumentException $e) {
            return $this->failOrRetryRun($run, ['name' => 'ProtocolError', 'message' => 'wait_for_event: '.$e->getMessage()], false);
        }

        $this->meter($run);
        $step->fill([
            'status' => WorkflowStep::WAITING,
            'attempt' => $step->attempt + 1,
            'wait_event' => $op['event'],
            'wait_expression' => $expression,
            'timeout_at' => $timeoutAt,
            'input' => array_filter([
                'event' => $op['event'],
                'match' => $op['match'] ?? null,
                'if' => $op['if'] ?? null,
                'timeout' => $op['timeout'] ?? null,
            ], fn ($v) => $v !== null),
        ]);
        $this->saveStep($run, $step, $new);

        return $this->park($run, WorkflowRun::WAITING, $timeoutAt, 'waiting');
    }

    private function onInvoke(WorkflowRun $run, array $op): string
    {
        [$step, $new] = $this->stepFor($run, $op, WorkflowStep::TYPE_INVOKE);
        if ($step === null) {
            return $this->tooManySteps($run);
        }
        if (! $new && $step->isMemoized()) {
            return $this->requeue($run, null, 'duplicate');
        }

        try {
            $timeoutAt = $this->timeoutAt($op['timeout'] ?? null, $run);
        } catch (InvalidArgumentException $e) {
            return $this->failOrRetryRun($run, ['name' => 'ProtocolError', 'message' => 'invoke: '.$e->getMessage()], false);
        }

        $this->meter($run);
        $step->fill([
            'attempt' => $step->attempt + 1,
            'timeout_at' => $timeoutAt,
            'input' => ['workflow' => $op['workflow'], 'data' => $op['data'] ?? null, 'timeout' => $op['timeout'] ?? null],
        ]);

        $target = Workflow::where('project_id', $run->project_id)->where('name', $op['workflow'])->first();
        if (! $target) {
            return $this->failStep($run, $step, $new, ['name' => 'WorkflowNotFound', 'message' => __('workflows.invoke_not_found', ['name' => $op['workflow']])], 0);
        }

        $step->fill(['status' => WorkflowStep::WAITING]);
        $this->saveStep($run, $step, $new);

        try {
            $child = $this->starter->start($target, ['name' => 'runr/invoke', 'data' => $op['data'] ?? null], [
                'source' => 'invoke',
                'parent_run_id' => $run->id,
                'parent_step_id' => $step->id,
                'idempotency_key' => 'invoke:'.$step->id,
            ]);
        } catch (WorkflowException $e) {
            return $this->failStep($run, $step, false, ['name' => 'InvokeFailed', 'message' => $e->getMessage()], 0);
        }

        $step->forceFill(['child_run_id' => $child->run->id])->save();
        $outcome = $this->park($run, WorkflowRun::WAITING, $timeoutAt, 'invoking');

        // Same step invoked again (idempotent child) after the child already ended
        if ($child->run->isTerminal()) {
            $this->control->resolveParent($child->run);
        }

        return $outcome;
    }

    private function onSendEvent(WorkflowRun $run, array $op): string
    {
        [$step, $new] = $this->stepFor($run, $op, WorkflowStep::TYPE_SEND_EVENT);
        if ($step === null) {
            return $this->tooManySteps($run);
        }
        if (! $new && $step->isMemoized()) {
            return $this->requeue($run, null, 'duplicate');
        }

        $this->meter($run);
        $step->attempt = $step->attempt + 1;

        try {
            $results = $this->events->ingest(array_values((array) $op['events']), 'workflow', $run->project_id, 'run:'.$run->id);
        } catch (WorkflowException $e) {
            return $this->failStep($run, $step, $new, ['name' => 'SendEventFailed', 'message' => $e->getMessage()], 0);
        }

        $step->fill([
            'status' => WorkflowStep::COMPLETED,
            'input' => ['count' => count($results)],
            'output' => ['ids' => array_column($results, 'id')],
            'ended_at' => now(),
            'duration_ms' => 0,
        ]);
        $this->saveStep($run, $step, $new);

        // The event may have cancelled this very run (cancel_on)
        $status = WorkflowRun::whereKey($run->id)->value('status');
        if ($status !== WorkflowRun::RUNNING) {
            return 'cancelled_by_event';
        }

        return $this->requeue($run, null, 'events_sent');
    }

    private function onRunCompleted(WorkflowRun $run, array $op): string
    {
        $output = $op['output'] ?? null;
        $max = (int) config('workflows.limits.max_run_output_bytes', 1048576);

        if (Protocol::size($output) > $max) {
            return $this->finish($run, WorkflowRun::FAILED, null, ['name' => 'OutputTooLarge', 'message' => __('workflows.run_output_too_large', ['max' => $max])]);
        }

        return $this->finish($run, WorkflowRun::COMPLETED, $output, null);
    }

    // ── Run-level errors ───────────────────────────────────────────────────

    /** Function-level failure outside a step (unreachable, protocol error, uncaught error). */
    private function runError(WorkflowRun $run, string $token, array $error, bool $retriable): string
    {
        return DB::transaction(function () use ($run, $token, $error, $retriable) {
            $fresh = WorkflowRun::with('workflow')->lockForUpdate()->find($run->id);
            if (! $fresh || $fresh->status !== WorkflowRun::RUNNING || $fresh->lease_token !== $token) {
                return 'lease_lost';
            }

            return $this->failOrRetryRun($fresh, $error, $retriable);
        });
    }

    /** A running run whose lease expired (worker lost): counts as a failed attempt. */
    public function recoverExpiredLease(WorkflowRun $run): bool
    {
        return DB::transaction(function () use ($run) {
            $fresh = WorkflowRun::with('workflow')->lockForUpdate()->find($run->id);
            if (! $fresh || $fresh->status !== WorkflowRun::RUNNING || ! $fresh->lease_until || $fresh->lease_until->isFuture()) {
                return false;
            }

            $this->failOrRetryRun($fresh, ['name' => 'WorkerLost', 'message' => 'The engine worker did not finish the invocation within its lease'], true);

            return true;
        });
    }

    private function failOrRetryRun(WorkflowRun $run, array $error, bool $retriable): string
    {
        $attempt = (int) $run->attempt + 1;

        if ($retriable && $attempt < $run->workflow->maxAttempts()) {
            $at = now()->addMilliseconds(Backoff::fromConfig()->delayMs($attempt));
            $run->forceFill([
                'status' => WorkflowRun::QUEUED,
                'attempt' => $attempt,
                'error' => Protocol::normalizeError($error),
                'lease_token' => null,
                'lease_until' => null,
                'next_attempt_at' => $at,
            ])->save();
            $this->dispatcher->dispatch($run->id, $at);
            $this->control->releaseSlot($run);

            return 'run_retrying';
        }

        $run->attempt = $attempt;

        return $this->finish($run, WorkflowRun::FAILED, null, Protocol::normalizeError($error));
    }

    private function finish(WorkflowRun $run, string $status, mixed $output, ?array $error): string
    {
        $run->forceFill([
            'status' => $status,
            'output' => $output,
            'error' => $error,
            'ended_at' => now(),
            'lease_token' => null,
            'lease_until' => null,
            'next_attempt_at' => null,
        ])->save();

        WorkflowStep::where('run_id', $run->id)
            ->whereIn('status', WorkflowStep::OPEN)
            ->update(['status' => WorkflowStep::CANCELLED, 'ended_at' => now(), 'updated_at' => now()]);

        $this->control->resolveParent($run);
        $this->control->releaseSlot($run);

        return $status === WorkflowRun::COMPLETED ? 'run_completed' : 'run_failed';
    }

    private function tooManySteps(WorkflowRun $run): string
    {
        $max = (int) config('workflows.limits.max_steps_per_run', 1000);

        return $this->finish($run, WorkflowRun::FAILED, null, ['name' => 'TooManySteps', 'message' => __('workflows.too_many_steps', ['max' => $max])]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @return array{0: WorkflowStep|null, 1: bool} the step for an op id; null when the step limit is reached */
    private function stepFor(WorkflowRun $run, array $op, string $type): array
    {
        $step = WorkflowStep::where('run_id', $run->id)->where('step_key', $op['id'])->first();
        if ($step) {
            return [$step, false];
        }

        if ($run->steps_count >= (int) config('workflows.limits.max_steps_per_run', 1000)) {
            return [null, true];
        }

        $name = is_string($op['name'] ?? null) && $op['name'] !== '' ? $op['name'] : preg_replace('/:\d+$/', '', $op['id']);

        return [new WorkflowStep([
            'run_id' => $run->id,
            'step_key' => $op['id'],
            'name' => mb_substr((string) $name, 0, 255),
            'type' => $type,
            'status' => WorkflowStep::RETRYING,
            'position' => (int) WorkflowStep::where('run_id', $run->id)->max('position') + 1,
            'attempt' => 0,
            'max_attempts' => $type === WorkflowStep::TYPE_RUN ? $run->workflow->maxAttempts() : 1,
            'started_at' => now(),
        ]), true];
    }

    private function saveStep(WorkflowRun $run, WorkflowStep $step, bool $new): void
    {
        $step->save();

        if ($new) {
            $run->steps_count = (int) $run->steps_count + 1;
            WorkflowRun::whereKey($run->id)->update(['steps_count' => $run->steps_count]);
        }
    }

    /** Final failure of a step ($step->attempt already counts this attempt). */
    private function failStep(WorkflowRun $run, WorkflowStep $step, bool $new, array $error, int $durationMs, bool $record = true): string
    {
        if ($record) {
            $this->recordAttempt($step, max(1, (int) $step->attempt), 'failed', $durationMs, $error);
        }

        $step->fill([
            'status' => WorkflowStep::FAILED,
            'error' => Protocol::normalizeError($error),
            'wake_at' => null,
            'ended_at' => now(),
            'duration_ms' => $durationMs,
        ]);
        $this->saveStep($run, $step, $new);

        // The function sees the failure on its next invocation and may handle it
        return $this->requeue($run, null, 'step_failed');
    }

    private function recordAttempt(WorkflowStep $step, int $attempt, string $status, int $durationMs, mixed $error = null): void
    {
        $history = $step->attempts ?? [];
        $history[] = array_filter([
            'attempt' => $attempt,
            'status' => $status,
            'at' => now()->toIso8601ZuluString('millisecond'),
            'duration_ms' => $durationMs,
            'error' => $error === null ? null : Protocol::normalizeError($error),
        ], fn ($v) => $v !== null);

        $step->attempts = array_slice($history, -50);
    }

    /** Back to the queue — now, or at $at (retry backoff). */
    private function requeue(WorkflowRun $run, ?\DateTimeInterface $at, string $outcome): string
    {
        $run->forceFill([
            'status' => WorkflowRun::QUEUED,
            'attempt' => 0,
            'lease_token' => null,
            'lease_until' => null,
            'next_attempt_at' => $at,
        ])->save();

        $this->dispatcher->dispatch($run->id, $at);

        return $outcome;
    }

    /** Sleeping / waiting until $until (wake-up or timeout check). */
    private function park(WorkflowRun $run, string $status, \DateTimeInterface $until, string $outcome): string
    {
        $run->forceFill([
            'status' => $status,
            'attempt' => 0,
            'lease_token' => null,
            'lease_until' => null,
            'next_attempt_at' => $until,
        ])->save();

        $this->dispatcher->dispatch($run->id, $until);
        $this->control->releaseSlot($run);

        return $outcome;
    }

    private function meter(WorkflowRun $run): void
    {
        $projectId = $run->project_id;
        $workflowId = $run->workflow_id;

        DB::afterCommit(fn () => WorkflowUsage::record($projectId, $workflowId, steps: 1));
    }

    private function opDuration(array $op, int $fallback): int
    {
        return isset($op['duration_ms']) && is_numeric($op['duration_ms']) ? max(0, (int) $op['duration_ms']) : $fallback;
    }

    private function retryDelayMs(array $op, int $attempt): int
    {
        $after = $op['retry_after'] ?? null;

        if ($after !== null) {
            if (Duration::isValid($after)) {
                return Duration::milliseconds($after);
            }
            try {
                return max(0, (int) (Duration::parseInstant($after)->getTimestampMs() - now()->getTimestampMs()));
            } catch (InvalidArgumentException) {
                // fall back to the backoff
            }
        }

        return Backoff::fromConfig()->delayMs($attempt);
    }

    /** Timeout of a wait / invoke: its own, else workflows.defaults.wait_timeout; never past the run timeout. */
    private function timeoutAt(mixed $raw, WorkflowRun $run): CarbonImmutable
    {
        $at = Duration::from($raw ?? config('workflows.defaults.wait_timeout', '7d'));

        if ($run->timeout_at && $at->greaterThan($run->timeout_at)) {
            $at = CarbonImmutable::instance($run->timeout_at);
        }

        return $at;
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
