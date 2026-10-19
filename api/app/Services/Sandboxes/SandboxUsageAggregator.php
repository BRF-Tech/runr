<?php

namespace App\Services\Sandboxes;

use App\Models\Sandbox;
use App\Models\SandboxUsageRecord;
use App\Services\Metering\UsageAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage metering of sandboxes into sandbox_usage_records: per organization × region × creator ×
 * day, the run time of every sandbox in that day (started_at → ended_at, or now) as sandbox seconds
 * and as reserved resources × time — cpu_m × ms and memory MiB × ms, the units of the project usage
 * records. Quantities only: the Billing module prices them (docs/billing.md) and bills them to the
 * creator — a sandbox belongs to no project. Warm pods are metered from their claim on
 * (started_at); idle pool capacity is not charged.
 *
 * Runs every five minutes (MeterSandboxUsageJob) for today, and for yesterday during the first hour
 * of a day so the last minutes of a day are not lost.
 */
final class SandboxUsageAggregator
{
    private ?bool $creatorColumn = null;

    /** Re-aggregate a day (UTC). Returns the number of records written. */
    public function aggregateDay(Carbon $day, ?Carbon $now = null): int
    {
        $now = ($now ?? now())->copy()->utc();
        $start = $day->copy()->utc()->startOfDay();
        $end = $start->copy()->addDay();
        $until = $now->lessThan($end) ? $now : $end;

        if ($until->lessThanOrEqualTo($start)) {
            return 0;
        }

        $rows = Sandbox::query()
            ->whereNotNull('started_at')
            ->where('started_at', '<', $until)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->get(['id', 'tenant_id', 'created_by', 'knative_region_id', 'cpu_m', 'memory_mb', 'started_at', 'ended_at']);

        $byCreator = $this->recordsCreator();

        $groups = [];
        foreach ($rows as $row) {
            $seconds = self::overlapSeconds($row->started_at, $row->ended_at ?? $until, $start, $until);
            $ms = self::overlapMs($row->started_at, $row->ended_at ?? $until, $start, $until);
            if ($ms <= 0) {
                continue;
            }

            $creator = $byCreator && $row->created_by !== null && $row->created_by !== '' ? (string) $row->created_by : null;
            $key = ($row->tenant_id ?? '').'|'.$row->knative_region_id.'|'.($creator ?? '');
            $groups[$key] ??= [
                'tenant_id' => $row->tenant_id, 'region' => $row->knative_region_id, 'creator' => $creator,
                'count' => 0, 'seconds' => 0, 'cpu' => 0, 'mem' => 0,
            ];
            $groups[$key]['count']++;
            $groups[$key]['seconds'] += $seconds;
            $groups[$key]['cpu'] += $row->cpu_m * $ms;
            $groups[$key]['mem'] += $row->memory_mb * $ms;
        }

        // The day is recomputed from every sandbox that ran in it: its rows are replaced as a whole
        // (also the per-organization rows of earlier releases, now split per creator)
        $date = $start->toDateString();
        $model = new SandboxUsageRecord;
        DB::connection($model->getConnectionName())->transaction(function () use ($groups, $date, $byCreator) {
            SandboxUsageRecord::query()->whereDate('record_date', $date)->delete();

            foreach ($groups as $g) {
                $attributes = [
                    'tenant_id' => $g['tenant_id'],
                    'knative_region_id' => $g['region'],
                    'record_date' => $date,
                    'sandbox_count' => $g['count'],
                    'sandbox_seconds' => $g['seconds'],
                    'cpu_mc_ms' => (int) $g['cpu'],
                    'memory_mib_ms' => (int) $g['mem'],
                    // Quantities only — prices are applied when bills are calculated (docs/billing.md)
                    'raw_cost' => 0,
                    'multiplier' => 1,
                    'final_cost' => 0,
                    'currency' => UsageAggregator::currency(),
                ];
                if ($byCreator) {
                    $attributes['created_by'] = $g['creator'];
                }

                SandboxUsageRecord::query()->create($attributes);
            }
        });

        return count($groups);
    }

    /** Today, plus yesterday during the first hour of the day. */
    public function aggregateRecent(?Carbon $now = null): int
    {
        $now = ($now ?? now())->copy()->utc();
        $written = $this->aggregateDay($now->copy()->startOfDay(), $now);

        if ($now->hour === 0) {
            $written += $this->aggregateDay($now->copy()->subDay()->startOfDay(), $now);
        }

        return $written;
    }

    /** sandbox_usage_records.created_by exists (database/migrations/2026_10_19_100002). */
    private function recordsCreator(): bool
    {
        return $this->creatorColumn ??= Schema::connection((new SandboxUsageRecord)->getConnectionName())
            ->hasColumn((new SandboxUsageRecord)->getTable(), 'created_by');
    }

    public static function overlapSeconds(Carbon $from, Carbon $to, Carbon $windowStart, Carbon $windowEnd): int
    {
        return intdiv(self::overlapMs($from, $to, $windowStart, $windowEnd), 1000);
    }

    public static function overlapMs(Carbon $from, Carbon $to, Carbon $windowStart, Carbon $windowEnd): int
    {
        $a = max($from->getTimestampMs(), $windowStart->getTimestampMs());
        $b = min($to->getTimestampMs(), $windowEnd->getTimestampMs());

        return max(0, $b - $a);
    }
}
