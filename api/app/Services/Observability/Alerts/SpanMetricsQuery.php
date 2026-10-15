<?php

namespace App\Services\Observability\Alerts;

use App\Models\KnativeRegion;
use App\Services\Helpers\MonitoringEndpoint;
use App\Services\Observability\InClusterHttp;

/**
 * The span metrics of a traced project, from the region's Prometheus: the OpenTelemetry
 * Collector's spanmetrics connector counts every SERVER span (calls, duration histogram, the
 * runr.cold_start dimension) per service — the project's slug. Sampling keeps ratios and
 * percentiles representative; absolute counts scale with the sampling ratio.
 */
class SpanMetricsQuery
{
    public function __construct(private InClusterHttp $http) {}

    /**
     * PromQL of a metric rule type over $window minutes for the service.
     *
     * @return array{value: string, volume: string}
     */
    public static function promql(string $type, string $service, int $window): array
    {
        $m = (array) config('observability.alerts.metrics', []);
        $calls = $m['calls'] ?? 'traces_span_metrics_calls_total';
        $dur = $m['duration'] ?? 'traces_span_metrics_duration_milliseconds_bucket';
        $svc = $m['service'] ?? 'service_name';
        $cold = $m['cold'] ?? 'runr_cold_start';
        $w = max(1, $window).'m';
        $service = addcslashes($service, '\\"');
        $sel = "{$svc}=\"{$service}\",span_kind=\"SPAN_KIND_SERVER\"";

        $volume = "sum(increase({$calls}{{$sel}}[{$w}]))";

        $value = match ($type) {
            'error_rate' => "100 * sum(rate({$calls}{{$sel},status_code=\"STATUS_CODE_ERROR\"}[{$w}])) / sum(rate({$calls}{{$sel}}[{$w}]))",
            'cold_start_rate' => "100 * sum(rate({$calls}{{$sel},{$cold}=\"true\"}[{$w}])) / sum(rate({$calls}{{$sel}}[{$w}]))",
            'p95_latency' => "histogram_quantile(0.95, sum by (le) (rate({$dur}{{$sel}}[{$w}])))",
            default => throw new \InvalidArgumentException("not a metric rule type: {$type}"),
        };

        return ['value' => $value, 'volume' => $volume];
    }

    /**
     * [value, request volume] — value null when there is no data (no traffic, metrics not scraped).
     *
     * @return array{0: float|null, 1: float}
     */
    public function measure(KnativeRegion $region, string $type, string $service, int $window): array
    {
        $q = self::promql($type, $service, $window);

        return [$this->instant($region, $q['value']), (float) ($this->instant($region, $q['volume']) ?? 0.0)];
    }

    private function instant(KnativeRegion $region, string $promql): ?float
    {
        $response = $this->http->get($region, MonitoringEndpoint::prometheus($region), 'api/v1/query', ['query' => $promql]);
        if (! $response || ! $response->successful()) {
            return null;
        }

        $value = $response->json('data.result.0.value.1');
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $f = (float) $value;

        return is_nan($f) || is_infinite($f) ? null : $f;
    }
}
