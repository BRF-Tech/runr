<?php

namespace App\Services\Rollouts;

use App\Models\Project;
use App\Services\Observability\Alerts\SpanMetricsQuery;

/**
 * The error rate and p95 latency a canary step is judged by — the new revision's own, over the
 * time the step has run, from the region's Prometheus:
 *
 *  1. span metrics of the revision (source "span_metrics"): while tracing is on for the project
 *     and its region accepts spans (DeployStrategies::tracing), the series of SpanMetricsQuery —
 *     the same as the alert rules' — narrowed to the revision: the rollout names its revision and
 *     gives its pods the resource attribute runr.revision (RolloutTraffic::pinCandidate), which
 *     the collector's spanmetrics connector keeps as a dimension (ManagedTracing);
 *  2. otherwise Knative's request metrics of the revision (source "knative"): the queue-proxy
 *     beside every revision pod counts each request by response code class and measures its
 *     latency, labelled with revision_name — where the region's Prometheus scrapes them;
 *  3. neither: no source, the canary progresses on time only.
 *
 * A source counts once it has requests of the revision in the window. Earlier releases judged
 * the project's whole traffic, of which the new revision had a share.
 */
class RolloutMetrics
{
    public const SOURCE_SPANS = 'span_metrics';

    public const SOURCE_KNATIVE = 'knative';

    /**
     * Knative's request metrics per revision, in the names of both of its metric pipelines
     * (OpenCensus and OpenTelemetry exporters): request count by response code class, request
     * latency histogram in milliseconds.
     */
    private const KNATIVE_COUNTS = ['revision_request_count_total', 'kn_revision_request_count_total'];

    private const KNATIVE_LATENCIES = ['revision_request_latencies_bucket', 'kn_revision_request_latencies_bucket'];

    public function __construct(
        private SpanMetricsQuery $query,
        private DeployStrategies $strategies,
    ) {}

    /**
     * @return array{tracing: bool, source: string|null, revision: string|null, error_rate: float|null, p95_ms: float|null, volume: float, window_minutes: int, measured_at: string}
     */
    public function measure(Project $project, int $windowMinutes, ?string $revision = null): array
    {
        $window = max(1, $windowMinutes);
        $tracing = $this->strategies->tracing($project);
        $result = [
            'tracing' => $tracing,
            'source' => null,
            'revision' => $revision,
            'error_rate' => null,
            'p95_ms' => null,
            'volume' => 0.0,
            'window_minutes' => $window,
            'measured_at' => now()->toIso8601String(),
        ];

        $region = $project->knativeRegion;
        if (! $region || $revision === null || $revision === '') {
            return $result;
        }

        try {
            if ($tracing) {
                $labels = ['revision' => $revision];
                [$errorRate, $volume] = $this->query->measure($region, 'error_rate', $project->slug, $window, $labels);
                if ($volume > 0) {
                    [$p95] = $this->query->measure($region, 'p95_latency', $project->slug, $window, $labels);

                    return self::filled($result, self::SOURCE_SPANS, $errorRate, $p95, $volume);
                }
            }

            $namespace = (string) ($region->project_namespace ?: $region->name);
            $queries = self::knativePromql($namespace, $revision, $window);
            $volume = (float) ($this->query->instant($region, $queries['volume']) ?? 0.0);
            if ($volume > 0) {
                return self::filled($result, self::SOURCE_KNATIVE,
                    $this->query->instant($region, $queries['error_rate']) ?? 0.0,
                    $this->query->instant($region, $queries['p95_latency']),
                    $volume);
            }
        } catch (\Throwable) {
            // Prometheus not reachable: the step goes on, on time only
        }

        return $result;
    }

    /**
     * PromQL of Knative's request metrics of one revision over $window minutes: the requests, the
     * share of 5xx answers in percent and the p95 latency in milliseconds. Each sums both metric
     * names — a region exports one of them.
     *
     * @return array{volume: string, error_rate: string, p95_latency: string}
     */
    public static function knativePromql(string $namespace, string $revision, int $window): array
    {
        $w = max(1, $window).'m';
        $sel = 'namespace_name="'.addcslashes($namespace, '\\"').'",revision_name="'.addcslashes($revision, '\\"').'"';
        $sum = fn (array $names, string $selector, string $fn, string $by = '') => '('.implode(' or ', array_map(
            fn (string $name) => "sum{$by}({$fn}({$name}{{$selector}}[{$w}]))", $names,
        )).')';

        $requests = $sum(self::KNATIVE_COUNTS, $sel, 'rate');
        $errors = $sum(self::KNATIVE_COUNTS, $sel.',response_code_class="5xx"', 'rate');

        return [
            'volume' => $sum(self::KNATIVE_COUNTS, $sel, 'increase'),
            // no 5xx series at all: 0 % (the "or" keeps a value while requests exist)
            'error_rate' => "100 * ({$errors} or vector(0)) / {$requests}",
            'p95_latency' => 'histogram_quantile(0.95, '.$sum(self::KNATIVE_LATENCIES, $sel, 'rate', ' by (le)').')',
        ];
    }

    /** @param array<string, mixed> $result */
    private static function filled(array $result, string $source, ?float $errorRate, ?float $p95, float $volume): array
    {
        return [
            'source' => $source,
            'error_rate' => $errorRate !== null ? round($errorRate, 2) : null,
            'p95_ms' => $p95 !== null ? round($p95, 1) : null,
            'volume' => round($volume, 1),
        ] + $result;
    }

    /**
     * Why the step fails its thresholds, or null: with enough requests of the new revision in the
     * window (knative.rollouts.min_requests), an error rate or p95 above the canary's limits.
     * Metrics of an earlier release (without source) came from the span metrics when tracing was on.
     *
     * @param  array<string, mixed>  $metrics  measure()'s answer
     * @param  array<string, mixed>  $options  the canary options
     */
    public static function breach(array $metrics, array $options): ?string
    {
        $source = $metrics['source'] ?? (($metrics['tracing'] ?? false) ? self::SOURCE_SPANS : null);
        if ($source === null || (float) ($metrics['volume'] ?? 0) < max(1, (int) config('knative.rollouts.min_requests', 10))) {
            return null;
        }

        $errorLimit = $options['error_rate_threshold'] ?? null;
        if ($errorLimit !== null && ($metrics['error_rate'] ?? null) !== null && (float) $metrics['error_rate'] > (float) $errorLimit) {
            return __('rollouts.breach_error_rate', ['value' => $metrics['error_rate'], 'limit' => $errorLimit, 'window' => $metrics['window_minutes'] ?? 1]);
        }

        $p95Limit = $options['p95_threshold_ms'] ?? null;
        if ($p95Limit !== null && ($metrics['p95_ms'] ?? null) !== null && (float) $metrics['p95_ms'] > (float) $p95Limit) {
            return __('rollouts.breach_p95', ['value' => $metrics['p95_ms'], 'limit' => $p95Limit, 'window' => $metrics['window_minutes'] ?? 1]);
        }

        return null;
    }
}
