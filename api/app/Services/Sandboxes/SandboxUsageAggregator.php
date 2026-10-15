<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Models\SandboxUsageRecord;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Services\CostCalculationService;

/**
 * Usage metering of sandboxes into sandbox_usage_records: per organization × region × day, the
 * run time of every sandbox in that day (started_at → ended_at, or now) as sandbox seconds and as
 * reserved resources × time — cpu_m × ms and memory MiB × ms, the units of the project usage
 * records — priced with the region's cost config (Tenancy module's CostConfig, entry point
 * "sandbox" → multiplier 1) when the module is installed, at zero cost otherwise. Warm pods are
 * metered from their claim on (started_at); idle pool capacity is not charged.
 *
 * Runs every five minutes (MeterSandboxUsageJob) for today, and for yesterday during the first hour
 * of a day so the last minutes of a day are not lost. Usage charges of the Tenancy module get one
 * line per region (chargeItems()).
 */
final class SandboxUsageAggregator
{
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
            ->get(['id', 'tenant_id', 'knative_region_id', 'cpu_m', 'memory_mb', 'started_at', 'ended_at']);

        $groups = [];
        foreach ($rows as $row) {
            $seconds = self::overlapSeconds($row->started_at, $row->ended_at ?? $until, $start, $until);
            $ms = self::overlapMs($row->started_at, $row->ended_at ?? $until, $start, $until);
            if ($ms <= 0) {
                continue;
            }

            $key = ($row->tenant_id ?? '').'|'.$row->knative_region_id;
            $groups[$key] ??= [
                'tenant_id' => $row->tenant_id, 'region' => $row->knative_region_id,
                'count' => 0, 'seconds' => 0, 'cpu' => 0, 'mem' => 0,
            ];
            $groups[$key]['count']++;
            $groups[$key]['seconds'] += $seconds;
            $groups[$key]['cpu'] += $row->cpu_m * $ms;
            $groups[$key]['mem'] += $row->memory_mb * $ms;
        }

        $date = $start->toDateString();
        foreach ($groups as $g) {
            $cost = $this->cost((int) $g['cpu'], (int) $g['mem'], $g['region']);

            $record = SandboxUsageRecord::query()
                ->when($g['tenant_id'] === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $g['tenant_id']))
                ->where('knative_region_id', $g['region'])
                ->whereDate('record_date', $date)
                ->first() ?? new SandboxUsageRecord([
                    'tenant_id' => $g['tenant_id'],
                    'knative_region_id' => $g['region'],
                    'record_date' => $date,
                ]);

            $record->fill([
                'sandbox_count' => $g['count'],
                'sandbox_seconds' => $g['seconds'],
                'cpu_mc_ms' => (int) $g['cpu'],
                'memory_mib_ms' => (int) $g['mem'],
                'raw_cost' => $cost['raw_cost'],
                'multiplier' => $cost['multiplier'],
                'final_cost' => $cost['final_cost'],
                'currency' => $cost['currency'],
            ])->save();
        }

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

    /** @return array{raw_cost: float, multiplier: float, final_cost: float, currency: string} */
    private function cost(int $cpuMcMs, int $memoryMibMs, string $regionId): array
    {
        if (class_exists(CostCalculationService::class) && config('tenancy.enabled')) {
            try {
                return app(CostCalculationService::class)
                    ->calculateSessionCost($cpuMcMs, $memoryMibMs, 'sandbox', $regionId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'raw_cost' => 0.0,
            'multiplier' => 1.0,
            'final_cost' => 0.0,
            'currency' => strtoupper((string) config('tenancy.billing.default_currency', 'USD')) ?: 'USD',
        ];
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

    /**
     * Usage-charge lines (Tenancy billing, docs/billing.md) for an organization's sandbox usage in
     * a period and currency: one line per region, quantity = sandbox hours.
     *
     * @return list<array{description: string, quantity: float, unit: string, unit_price: float, total: float, metadata: array}>
     */
    public static function chargeItems(string $tenantId, string $from, string $to, string $currency): array
    {
        $records = SandboxUsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('record_date', '>=', $from)
            ->whereDate('record_date', '<=', $to)
            ->get()
            ->filter(fn ($r) => strtoupper((string) $r->currency) === strtoupper($currency));

        $items = [];
        foreach ($records->groupBy('knative_region_id') as $regionId => $group) {
            $total = round((float) $group->sum(fn ($r) => (float) $r->final_cost), 2);
            if ($total <= 0) {
                continue;
            }
            $hours = round($group->sum('sandbox_seconds') / 3600, 2);

            $regionName = KnativeRegion::query()->whereKey($regionId)->value('name') ?? $regionId;

            $items[] = [
                'description' => sprintf('Sandboxes (%s)', $regionName),
                'quantity' => $hours,
                'unit' => 'hours',
                'unit_price' => $hours > 0 ? round($total / $hours, 6) : $total,
                'total' => $total,
                'metadata' => [
                    'source' => 'sandbox',
                    'region_id' => $regionId,
                    'sandbox_count' => (int) $group->sum('sandbox_count'),
                    'sandbox_seconds' => (int) $group->sum('sandbox_seconds'),
                    'cpu_mc_ms' => (int) $group->sum('cpu_mc_ms'),
                    'memory_mib_ms' => (int) $group->sum('memory_mib_ms'),
                    'raw_cost' => round((float) $group->sum(fn ($r) => (float) $r->raw_cost), 6),
                ],
            ];
        }

        return $items;
    }

    /**
     * Currencies of an organization's sandbox usage in a period (upper case).
     *
     * @return list<string>
     */
    public static function currencies(string $tenantId, string $from, string $to): array
    {
        return SandboxUsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('record_date', '>=', $from)
            ->whereDate('record_date', '<=', $to)
            ->pluck('currency')
            ->map(fn ($c) => strtoupper((string) $c))
            ->unique()
            ->values()
            ->all();
    }

    /** Sandbox cost of an organization since $from (billing summary). */
    public static function costSince(?string $tenantId, string $from): float
    {
        return (float) SandboxUsageRecord::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereDate('record_date', '>=', $from)
            ->sum('final_cost');
    }
}
