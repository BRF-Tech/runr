<?php

namespace App\Services\ApiProduct;

/**
 * What the functions behind key / JWT routes answered (docs/api-product.md#usage), as gateway-auth's
 * metering proxy reports it per usage row: status classes, calls that got no answer (upstream
 * errors), the latency until the answer started — a sum and a histogram of fixed buckets, so rows
 * of any pods, reports, hours, consumers or routes add up and still give percentiles — and the
 * bytes of request and answer bodies.
 *
 * Bucket i of the histogram (column latency_bNN) counts the answers with a latency up to
 * BOUNDS_MS[i] milliseconds and above the bound before; the last one those above the last bound.
 * runtime/gateway-auth/usage.go (LatencyBoundsMS) has the same bounds: change both together.
 */
final class ApiResponseMetrics
{
    public const BOUNDS_MS = [2, 5, 10, 20, 30, 50, 75, 100, 150, 200, 300, 500, 750, 1000, 2000, 3000, 5000, 10000, 30000];

    /** Counters of the answers, summed as they are. */
    public const COUNTERS = ['status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'upstream_errors', 'latency_us_sum', 'bytes_in', 'bytes_out'];

    /** What a report shows of them, next to the gateway's own counters. */
    public const FIELDS = [
        'responses', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'upstream_errors', 'errors',
        'latency_avg_ms', 'latency_p50_ms', 'latency_p95_ms', 'latency_p99_ms', 'bytes_in', 'bytes_out',
    ];

    /** @return list<string> latency_b00 … latency_b19 */
    public static function bucketColumns(): array
    {
        $columns = [];
        for ($i = 0; $i <= count(self::BOUNDS_MS); $i++) {
            $columns[] = sprintf('latency_b%02d', $i);
        }

        return $columns;
    }

    /** @return list<string> every column a usage row adds up */
    public static function columns(): array
    {
        return [...self::COUNTERS, ...self::bucketColumns()];
    }

    /**
     * The report fields of summed columns ($sums: column → sum).
     *
     * @param  array<string, int|float|string|null>  $sums
     * @return array<string, int|float|null>
     */
    public static function present(array $sums): array
    {
        $n = fn (string $c) => (int) ($sums[$c] ?? 0);
        $buckets = array_map(fn (string $c) => $n($c), self::bucketColumns());
        $answered = array_sum($buckets);

        return [
            'responses' => $n('status_2xx') + $n('status_3xx') + $n('status_4xx') + $n('status_5xx') + $n('upstream_errors'),
            'status_2xx' => $n('status_2xx'),
            'status_3xx' => $n('status_3xx'),
            'status_4xx' => $n('status_4xx'),
            'status_5xx' => $n('status_5xx'),
            'upstream_errors' => $n('upstream_errors'),
            // the function failed or did not answer
            'errors' => $n('status_5xx') + $n('upstream_errors'),
            'latency_avg_ms' => $answered > 0 ? round($n('latency_us_sum') / $answered / 1000, 1) : null,
            'latency_p50_ms' => self::percentile($buckets, 0.50),
            'latency_p95_ms' => self::percentile($buckets, 0.95),
            'latency_p99_ms' => self::percentile($buckets, 0.99),
            'bytes_in' => $n('bytes_in'),
            'bytes_out' => $n('bytes_out'),
        ];
    }

    /**
     * The q-quantile (0..1) of a histogram in milliseconds, interpolated inside its bucket; above the
     * last bound it is that bound. Null without answers.
     *
     * @param  list<int>  $buckets  count per bucket (bucketColumns() order)
     */
    public static function percentile(array $buckets, float $q): ?float
    {
        $total = array_sum($buckets);
        if ($total <= 0) {
            return null;
        }

        $rank = max(0.0, min(1.0, $q)) * $total;
        $seen = 0;
        foreach ($buckets as $i => $count) {
            if ($count <= 0) {
                continue;
            }
            if ($seen + $count >= $rank) {
                $lower = $i === 0 ? 0.0 : (float) self::BOUNDS_MS[$i - 1];
                if (! isset(self::BOUNDS_MS[$i])) {
                    return $lower;
                }
                $upper = (float) self::BOUNDS_MS[$i];

                return round($lower + ($upper - $lower) * (($rank - $seen) / $count), 1);
            }
            $seen += $count;
        }

        return (float) self::BOUNDS_MS[count(self::BOUNDS_MS) - 1];
    }
}
