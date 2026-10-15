<?php

namespace App\Services\Sandboxes;

use App\Models\Sandbox;
use App\Models\SandboxPool;

/**
 * Warm pool and start-time metrics from the sandbox rows: pool hits / misses (pool counters) and
 * histograms of start_ms — from the create request to a running sandbox, env and files included —
 * for pool hits and cold starts separately. JSON (GET /sandbox-metrics) and the Prometheus text
 * format (GET /sandbox-metrics/prometheus).
 */
final class SandboxMetrics
{
    /** Histogram bucket bounds in ms (cumulative, +Inf last) */
    public const BUCKETS_MS = [100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000];

    public const WINDOWS = ['1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    /**
     * @param  string|null|false  $tenantId  false: every organization (platform view)
     */
    public function summary(string|null|false $tenantId, ?string $regionId = null, string $window = '24h'): array
    {
        $seconds = self::WINDOWS[$window] ?? self::WINDOWS['24h'];
        $since = now()->subSeconds($seconds);

        $scope = function ($query) use ($tenantId, $regionId) {
            return $query
                ->when($tenantId !== false, fn ($q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId))
                ->when($regionId, fn ($q) => $q->where('knative_region_id', $regionId));
        };

        $started = $scope(Sandbox::query())
            ->whereNotNull('start_ms')
            ->where('started_at', '>=', $since)
            ->get(['start_ms', 'from_pool']);

        $hits = $started->where('from_pool', true)->count();
        $misses = (int) $scope(SandboxPool::query())->sum('misses');
        $hitsTotal = (int) $scope(SandboxPool::query())->sum('hits');

        return [
            'window' => $window,
            'active' => $scope(Sandbox::query())->whereIn('status', [Sandbox::STATUS_PENDING, Sandbox::STATUS_RUNNING, Sandbox::STATUS_PAUSED])->count(),
            'warm' => $scope(Sandbox::query())->where('status', Sandbox::STATUS_WARM)->count(),
            // Pool counters since the pools exist
            'pool' => [
                'hits' => $hitsTotal,
                'misses' => $misses,
                'hit_rate' => ($hitsTotal + $misses) > 0 ? round($hitsTotal / ($hitsTotal + $misses), 4) : null,
            ],
            // Sandboxes started in the window
            'starts' => [
                'pool_hit' => $hits,
                'cold' => $started->where('from_pool', false)->count(),
            ],
            'start_time' => [
                'pool_hit' => self::histogram($started->where('from_pool', true)->pluck('start_ms')->all()),
                'cold' => self::histogram($started->where('from_pool', false)->pluck('start_ms')->all()),
            ],
        ];
    }

    /** @param list<int> $values */
    public static function histogram(array $values): array
    {
        $buckets = [];
        foreach (self::BUCKETS_MS as $le) {
            $buckets[] = ['le_ms' => $le, 'count' => count(array_filter($values, fn ($v) => $v <= $le))];
        }
        $buckets[] = ['le_ms' => null, 'count' => count($values)];

        return ['count' => count($values), 'sum_ms' => (int) array_sum($values), 'buckets' => $buckets];
    }

    /** Prometheus text exposition of every organization's metrics (platform view). */
    public function prometheus(): string
    {
        $lines = [
            '# HELP runr_sandbox_start_seconds Time from a create request to a running sandbox.',
            '# TYPE runr_sandbox_start_seconds histogram',
        ];

        $rows = Sandbox::query()->whereNotNull('start_ms')->where('started_at', '>=', now()->subDays(30))
            ->get(['knative_region_id', 'from_pool', 'start_ms']);

        foreach ($rows->groupBy(fn ($r) => $r->knative_region_id.'|'.($r->from_pool ? 'true' : 'false')) as $key => $group) {
            [$region, $hit] = explode('|', $key);
            $labels = sprintf('region="%s",pool_hit="%s"', self::label($region), $hit);
            $h = self::histogram($group->pluck('start_ms')->all());
            foreach ($h['buckets'] as $bucket) {
                $le = $bucket['le_ms'] === null ? '+Inf' : rtrim(rtrim(sprintf('%.3f', $bucket['le_ms'] / 1000), '0'), '.');
                $lines[] = "runr_sandbox_start_seconds_bucket{{$labels},le=\"{$le}\"} {$bucket['count']}";
            }
            $lines[] = "runr_sandbox_start_seconds_sum{{$labels}} ".sprintf('%.3f', $h['sum_ms'] / 1000);
            $lines[] = "runr_sandbox_start_seconds_count{{$labels}} {$h['count']}";
        }

        $lines[] = '# HELP runr_sandbox_pool_claims_total Warm pool claims by result.';
        $lines[] = '# TYPE runr_sandbox_pool_claims_total counter';
        foreach (SandboxPool::all() as $pool) {
            $labels = sprintf('region="%s",pool="%s",runtime="%s"', self::label($pool->knative_region_id), self::label($pool->id), self::label($pool->runtime.':'.$pool->runtime_version));
            $lines[] = "runr_sandbox_pool_claims_total{{$labels},result=\"hit\"} {$pool->hits}";
            $lines[] = "runr_sandbox_pool_claims_total{{$labels},result=\"miss\"} {$pool->misses}";
        }

        $lines[] = '# HELP runr_sandboxes Sandboxes by status.';
        $lines[] = '# TYPE runr_sandboxes gauge';
        $counts = Sandbox::query()->whereIn('status', Sandbox::LIVE_STATUSES)
            ->selectRaw('knative_region_id, status, count(*) as n')->groupBy('knative_region_id', 'status')->get();
        foreach ($counts as $row) {
            $lines[] = sprintf('runr_sandboxes{region="%s",status="%s"} %d', self::label($row->knative_region_id), self::label($row->status), $row->n);
        }

        return implode("\n", $lines)."\n";
    }

    private static function label(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
