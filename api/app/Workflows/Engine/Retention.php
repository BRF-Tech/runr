<?php

namespace App\Workflows\Engine;

use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowUsage;
use Carbon\CarbonImmutable;

/**
 * Retention of one context (workflows:prune, daily): finished runs (with their steps) after
 * workflows.retention.runs_days, events after events_days, usage rows after usage_days.
 * Active runs are never pruned. An idempotency key is free again once its run is pruned.
 */
class Retention
{
    private const CHUNK = 500;

    /** @return array{runs: int, events: int, usage: int} */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $c = (array) config('workflows.retention', []);

        $runCutoff = $now->subDays((int) ($c['runs_days'] ?? 30));
        $eventCutoff = $now->subDays((int) ($c['events_days'] ?? 7));
        $usageCutoff = $now->subDays((int) ($c['usage_days'] ?? 400))->toDateString();

        $runs = 0;
        do {
            $ids = WorkflowRun::whereIn('status', WorkflowRun::TERMINAL)
                ->where('ended_at', '<', $runCutoff)
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                WorkflowStep::whereIn('run_id', $ids)->delete();
                $runs += WorkflowRun::whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === self::CHUNK);

        $events = 0;
        do {
            $deleted = WorkflowEvent::where('created_at', '<', $eventCutoff)->limit(self::CHUNK)->pluck('id');
            if ($deleted->isNotEmpty()) {
                $events += WorkflowEvent::whereIn('id', $deleted)->delete();
            }
        } while ($deleted->count() === self::CHUNK);

        $usage = WorkflowUsage::whereDate('date', '<', $usageCutoff)->delete();

        return ['runs' => $runs, 'events' => $events, 'usage' => $usage];
    }
}
