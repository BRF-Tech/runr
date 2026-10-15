<?php

namespace App\Workflows\Engine;

use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowTrigger;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-minute engine housekeeping of one context (workflows:tick):
 *   - run timeouts             active runs past timeout_at → timed_out
 *   - due sleeps               sleeping runs past next_attempt_at → queued
 *   - wait / invoke timeouts   waiting runs past next_attempt_at → waits resolve to null, invokes fail
 *   - expired leases           running runs past lease_until → retried (worker lost)
 *   - queued runs              due and not dispatched recently → dispatched again
 *   - cron triggers            due crons of startable workflows → one run per minute
 */
class Ticker
{
    public function __construct(
        private readonly WorkflowDispatcher $dispatcher,
        private readonly RunControl $control,
        private readonly RunStarter $starter,
        private readonly WorkflowExecutor $executor,
    ) {}

    /** @return array<string,int> counts per kind */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $batch = (int) config('workflows.tick_batch', 500);
        $stats = ['timed_out' => 0, 'woken' => 0, 'expired' => 0, 'recovered' => 0, 'redispatched' => 0, 'cron' => 0];

        foreach (WorkflowRun::whereIn('status', WorkflowRun::ACTIVE)->where('timeout_at', '<=', $now)->limit($batch)->get() as $run) {
            $stats['timed_out'] += (int) $this->control->timeOut($run);
        }

        foreach (WorkflowRun::where('status', WorkflowRun::SLEEPING)->where('next_attempt_at', '<=', $now)->limit($batch)->get() as $run) {
            $stats['woken'] += (int) $this->control->wakeDueSleeps($run);
        }

        foreach (WorkflowRun::where('status', WorkflowRun::WAITING)->where('next_attempt_at', '<=', $now)->limit($batch)->get() as $run) {
            $stats['expired'] += (int) $this->control->expireWaits($run);
        }

        foreach (WorkflowRun::where('status', WorkflowRun::RUNNING)->where('lease_until', '<', $now)->limit($batch)->get() as $run) {
            $stats['recovered'] += (int) $this->executor->recoverExpiredLease($run);
        }

        $stale = $now->subSeconds((int) config('workflows.redispatch_after_seconds', 120));
        $queued = WorkflowRun::where('status', WorkflowRun::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', $stale))
            ->orderBy('created_at')
            ->limit($batch)
            ->pluck('id');
        foreach ($queued as $id) {
            $this->dispatcher->dispatch($id);
            $stats['redispatched']++;
        }

        $stats['cron'] = $this->fireCrons($now);

        return $stats;
    }

    /** Start one run per due cron trigger for the current minute (idempotent per minute). */
    public function fireCrons(CarbonImmutable $now): int
    {
        $fired = 0;
        $minute = $now->startOfMinute();

        $triggers = WorkflowTrigger::where('type', WorkflowTrigger::TYPE_CRON)->with('workflow')->get();

        foreach ($triggers as $trigger) {
            $workflow = $trigger->workflow;
            if (! $workflow || ! $workflow->canStart() || ! $trigger->cron) {
                continue;
            }
            if ($trigger->last_fired_at && $trigger->last_fired_at->greaterThanOrEqualTo($minute)) {
                continue;
            }

            try {
                $tz = $trigger->timezone ?: 'UTC';
                $due = (new CronExpression($trigger->cron))->isDue($minute->setTimezone($tz), $tz);
            } catch (Throwable $e) {
                Log::warning('Invalid workflow cron trigger', ['trigger' => $trigger->id, 'error' => $e->getMessage()]);

                continue;
            }

            if (! $due) {
                continue;
            }

            try {
                $this->starter->start($workflow, [
                    'name' => 'runr/cron',
                    'data' => ['cron' => $trigger->cron, 'timezone' => $trigger->timezone, 'scheduled_at' => $minute->toIso8601ZuluString()],
                ], [
                    'source' => 'cron',
                    'idempotency_key' => 'cron:'.$trigger->id.':'.$minute->format('YmdHi'),
                ]);
                $fired++;
            } catch (WorkflowException $e) {
                Log::warning('Workflow cron trigger did not start a run', ['trigger' => $trigger->id, 'error' => $e->getMessage()]);
            }

            $trigger->forceFill(['last_fired_at' => $minute])->save();
        }

        return $fired;
    }
}
