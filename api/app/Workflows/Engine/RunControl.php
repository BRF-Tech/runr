<?php

namespace App\Workflows\Engine;

use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;

/**
 * State transitions outside a function invocation: cancel, time out, retry, replay, waking
 * sleeps, resolving waits and invoked children, and handing a freed concurrency slot on.
 * Every transition is a conditional UPDATE on the current status, so two workers racing on the
 * same run (an event and a timeout, a cancel and a step result) apply exactly one of them.
 */
class RunControl
{
    public function __construct(
        private readonly WorkflowDispatcher $dispatcher,
        private readonly RunStarter $starter,
    ) {}

    /** Cancel (or time out) an active run, its open steps and its invoked children. */
    public function cancel(WorkflowRun $run, ?string $reason = null, string $status = WorkflowRun::CANCELLED): bool
    {
        $error = $status === WorkflowRun::TIMED_OUT
            ? ['name' => 'RunTimeout', 'message' => $reason ?? 'The run exceeded its timeout']
            : ['name' => 'Cancelled', 'message' => $reason ?? 'The run was cancelled'];

        $changed = WorkflowRun::whereKey($run->id)
            ->whereIn('status', WorkflowRun::ACTIVE)
            ->update([
                'status' => $status,
                'error' => Protocol::encode($error),
                'ended_at' => now(),
                'lease_token' => null,
                'lease_until' => null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

        if (! $changed) {
            return false;
        }

        WorkflowStep::where('run_id', $run->id)
            ->whereIn('status', WorkflowStep::OPEN)
            ->update(['status' => WorkflowStep::CANCELLED, 'ended_at' => now(), 'updated_at' => now()]);

        foreach (WorkflowRun::where('parent_run_id', $run->id)->whereIn('status', WorkflowRun::ACTIVE)->get() as $child) {
            $this->cancel($child, 'The parent run '.$run->id.' ended', $status === WorkflowRun::TIMED_OUT ? WorkflowRun::CANCELLED : $status);
        }

        $run->refresh();
        $this->resolveParent($run);
        $this->releaseSlot($run);

        return true;
    }

    public function timeOut(WorkflowRun $run): bool
    {
        return $this->cancel($run, 'The run exceeded its timeout', WorkflowRun::TIMED_OUT);
    }

    /**
     * Retry a failed / cancelled / timed-out run in place: its completed steps stay memoized, the
     * failed and unfinished ones run again with fresh attempts.
     *
     * @throws WorkflowException
     */
    public function retry(WorkflowRun $run): WorkflowRun
    {
        if (! in_array($run->status, [WorkflowRun::FAILED, WorkflowRun::CANCELLED, WorkflowRun::TIMED_OUT], true)) {
            throw WorkflowException::conflict(__('workflows.retry_not_allowed', ['status' => $run->status]), 'run_not_retryable');
        }

        $workflow = $run->workflow;

        DB::transaction(function () use ($run, $workflow) {
            WorkflowStep::where('run_id', $run->id)->where('status', '!=', WorkflowStep::COMPLETED)->delete();

            $run->forceFill([
                'status' => WorkflowRun::QUEUED,
                'attempt' => 0,
                'retry_count' => $run->retry_count + 1,
                'error' => null,
                'output' => null,
                'ended_at' => null,
                'next_attempt_at' => null,
                'lease_token' => null,
                'lease_until' => null,
                'timeout_at' => now()->addSeconds($workflow->runTimeoutSeconds()),
                'steps_count' => WorkflowStep::where('run_id', $run->id)->count(),
            ])->save();
        });

        $this->dispatcher->dispatch($run->id);

        return $run->fresh();
    }

    /**
     * Replay a finished run as a new run with the same event — from the start, or from a step:
     * the memoized steps before it are copied, so execution resumes at that step.
     *
     * @throws WorkflowException
     */
    public function replay(WorkflowRun $run, ?string $fromStep = null, ?string $startedBy = null): WorkflowRun
    {
        if (! $run->isTerminal()) {
            throw WorkflowException::conflict(__('workflows.replay_active'), 'run_active');
        }

        $copy = collect();
        if ($fromStep !== null && $fromStep !== '') {
            $target = WorkflowStep::where('run_id', $run->id)->where('step_key', $fromStep)->first();
            if (! $target) {
                throw WorkflowException::invalid(__('workflows.step_not_found', ['step' => $fromStep]), 'step_not_found');
            }
            $copy = WorkflowStep::where('run_id', $run->id)
                ->where('position', '<', $target->position)
                ->whereIn('status', WorkflowStep::MEMOIZED)
                ->orderBy('position')
                ->get();
        }

        return $this->starter->start($run->workflow, $run->event ?? [], [
            'source' => 'replay',
            'event_id' => $run->event_id,
            'replay_of_run_id' => $run->id,
            'replay_from_step' => $fromStep ?: null,
            'started_by' => $startedBy,
            'allow_inactive' => true,
            'copy_steps' => $copy,
        ])->run;
    }

    /** waitForEvent resolved by an event (or, $payload null, by its timeout). */
    public function resolveWait(WorkflowStep $step, mixed $payload): bool
    {
        $now = now();
        $changed = WorkflowStep::whereKey($step->id)
            ->where('status', WorkflowStep::WAITING)
            ->update([
                'status' => WorkflowStep::COMPLETED,
                'output' => $payload === null ? null : Protocol::encode($payload),
                'ended_at' => $now,
                'duration_ms' => $step->started_at ? (int) $step->started_at->diffInMilliseconds($now, true) : null,
                'updated_at' => $now,
            ]);

        if ($changed) {
            $this->wake($step->run_id);
        }

        return (bool) $changed;
    }

    /** An invoked child run ended: its output (or failure) is the parent's invoke step result. */
    public function resolveParent(WorkflowRun $child): void
    {
        if (! $child->parent_run_id || ! $child->parent_step_id || ! $child->isTerminal()) {
            return;
        }

        $step = WorkflowStep::find($child->parent_step_id);
        if (! $step || $step->status !== WorkflowStep::WAITING) {
            return;
        }

        $now = now();
        $values = $child->status === WorkflowRun::COMPLETED
            ? [
                'status' => WorkflowStep::COMPLETED,
                'output' => $child->output === null ? null : Protocol::encode($child->output),
            ]
            : [
                'status' => WorkflowStep::FAILED,
                'error' => Protocol::encode([
                    'name' => 'InvokeFailed',
                    'message' => "Invoked run {$child->id} ended {$child->status}".(isset($child->error['message']) ? ': '.$child->error['message'] : ''),
                ]),
            ];

        $changed = WorkflowStep::whereKey($step->id)
            ->where('status', WorkflowStep::WAITING)
            ->update($values + [
                'ended_at' => $now,
                'duration_ms' => $step->started_at ? (int) $step->started_at->diffInMilliseconds($now, true) : null,
                'updated_at' => $now,
            ]);

        if ($changed) {
            $this->wake($step->run_id);
        }
    }

    /** Complete the due sleeps of a sleeping run and queue it. */
    public function wakeDueSleeps(WorkflowRun $run, bool $dispatch = true): bool
    {
        $now = now();
        $steps = WorkflowStep::where('run_id', $run->id)
            ->where('status', WorkflowStep::SLEEPING)
            ->where('wake_at', '<=', $now)
            ->get();

        foreach ($steps as $step) {
            WorkflowStep::whereKey($step->id)->where('status', WorkflowStep::SLEEPING)->update([
                'status' => WorkflowStep::COMPLETED,
                'ended_at' => $now,
                'duration_ms' => $step->started_at ? (int) $step->started_at->diffInMilliseconds($now, true) : null,
                'updated_at' => $now,
            ]);
        }

        return $this->wake($run->id, $dispatch);
    }

    /** Waits / invokes of a waiting run past their timeout: a wait resolves to null, an invoke fails. */
    public function expireWaits(WorkflowRun $run, bool $dispatch = true): bool
    {
        $now = now();
        $steps = WorkflowStep::where('run_id', $run->id)
            ->where('status', WorkflowStep::WAITING)
            ->where('timeout_at', '<=', $now)
            ->get();

        foreach ($steps as $step) {
            $values = $step->type === WorkflowStep::TYPE_INVOKE
                ? ['status' => WorkflowStep::FAILED, 'error' => Protocol::encode(['name' => 'InvokeTimeout', 'message' => 'The invoked run did not finish within the timeout'])]
                : ['status' => WorkflowStep::COMPLETED, 'output' => null];

            $changed = WorkflowStep::whereKey($step->id)->where('status', WorkflowStep::WAITING)->update($values + [
                'ended_at' => $now,
                'duration_ms' => $step->started_at ? (int) $step->started_at->diffInMilliseconds($now, true) : null,
                'updated_at' => $now,
            ]);

            if ($changed && $step->type === WorkflowStep::TYPE_INVOKE && $step->child_run_id && ($child = WorkflowRun::find($step->child_run_id))) {
                $this->cancel($child, 'The invoking step timed out');
            }
        }

        return $this->wake($run->id, $dispatch);
    }

    /** A sleeping / waiting run without open sleeps or waits goes back to the queue. */
    public function wake(string $runId, bool $dispatch = true): bool
    {
        $open = WorkflowStep::where('run_id', $runId)
            ->whereIn('status', [WorkflowStep::SLEEPING, WorkflowStep::WAITING])
            ->exists();

        if ($open) {
            return false;
        }

        $changed = WorkflowRun::whereKey($runId)
            ->whereIn('status', [WorkflowRun::SLEEPING, WorkflowRun::WAITING])
            ->update(['status' => WorkflowRun::QUEUED, 'next_attempt_at' => null, 'updated_at' => now()]);

        if ($changed && $dispatch) {
            $this->dispatcher->dispatch($runId);
        }

        return (bool) $changed;
    }

    /** A run left `running` (or ended): queued runs of its workflow may take the freed slot. */
    public function releaseSlot(WorkflowRun $run): void
    {
        $workflow = $run->workflow;
        $limits = $workflow?->concurrencyLimits() ?? [];
        if (! $limits) {
            return;
        }

        $take = max(array_map(fn ($l) => (int) $l['limit'], $limits));

        $next = WorkflowRun::where('workflow_id', $run->workflow_id)
            ->where('status', WorkflowRun::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($take)
            ->pluck('id');

        foreach ($next as $id) {
            $this->dispatcher->dispatch($id);
        }
    }
}
