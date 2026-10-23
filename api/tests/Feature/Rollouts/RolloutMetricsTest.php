<?php

namespace Tests\Feature\Rollouts;

use App\Models\Observability\ProjectObservabilitySetting;
use App\Services\Rollouts\RolloutMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * What a canary step is judged by (docs/deploy-strategies.md#canary-thresholds): the new
 * revision's own error rate and p95 latency — its span metrics (dimension runr.revision) while
 * tracing is on, otherwise Knative's request metrics of the revision (revision_name), otherwise
 * nothing and the canary moves on time only.
 */
class RolloutMetricsTest extends TestCase
{
    use FakesObservabilityCluster, RefreshDatabase;

    private const PROM = '/api/v1/namespaces/observability/services/http:prometheus-operated:9090/proxy/api/v1/query';

    private const REVISION = 'shop-v2-abc123';

    /** query fragment => answer (null: no series); the first fragment the query contains answers */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
        // not running in a pod: Prometheus is read through the region's API proxy
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);

        $this->k8sRoutes['GET '.self::PROM] = function () {
            $q = (string) ($this->k8sCalls[array_key_last($this->k8sCalls)]['query']['query'] ?? '');
            $v = null;
            foreach ($this->answers as $fragment => $answer) {
                if (str_contains($q, $fragment)) {
                    $v = $answer;
                    break;
                }
            }

            return [200, ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $v === null ? [] : [['metric' => [], 'value' => [1760000000, $v]]]]]];
        };
    }

    /** @return list<string> the PromQL queries sent */
    private function queries(): array
    {
        return array_values(array_map(fn ($c) => (string) ($c['query']['query'] ?? ''), $this->k8sCallsTo('GET', self::PROM)));
    }

    private function traced(bool $tracing = true): array
    {
        $region = $this->obsRegion();
        $this->tracingReady($region);
        $project = $this->obsProject($region);
        if ($tracing) {
            ProjectObservabilitySetting::forProject($project)->fill(['tracing_enabled' => true])->save();
        }

        return [$region, $project];
    }

    public function test_a_traced_canary_is_judged_by_the_span_metrics_of_its_revision(): void
    {
        [, $project] = $this->traced();
        $this->answers = [
            'sum(increase(traces_span_metrics_calls_total' => '120',
            'histogram_quantile' => '640',
            'STATUS_CODE_ERROR' => '7.25',
        ];

        $metrics = app(RolloutMetrics::class)->measure($project, 3, self::REVISION);

        $this->assertSame([RolloutMetrics::SOURCE_SPANS, self::REVISION, 7.25, 640.0, 120.0, 3],
            [$metrics['source'], $metrics['revision'], $metrics['error_rate'], $metrics['p95_ms'], $metrics['volume'], $metrics['window_minutes']]);
        foreach ($this->queries() as $query) {
            $this->assertStringContainsString('runr_revision="'.self::REVISION.'"', $query, 'the revision\'s own series, not the project\'s');
            $this->assertStringContainsString('[3m]', $query);
        }
        $this->assertSame('The new revision\'s error rate 7.25 % over the last 3 min is above the limit of 5 %.', RolloutMetrics::breach($metrics, ['error_rate_threshold' => 5]));
    }

    public function test_without_span_metrics_of_the_revision_knatives_request_metrics_judge_it(): void
    {
        [$region, $project] = $this->traced();
        $this->answers = [
            // the collector of an earlier release: no series with runr_revision
            'traces_span_metrics' => null,
            'response_code_class="5xx"' => '2.5',
            'histogram_quantile' => '310',
            'sum(increase(revision_request_count_total' => '80',
        ];

        $metrics = app(RolloutMetrics::class)->measure($project, 2, self::REVISION);

        $this->assertSame([RolloutMetrics::SOURCE_KNATIVE, 2.5, 310.0, 80.0], [$metrics['source'], $metrics['error_rate'], $metrics['p95_ms'], $metrics['volume']]);
        $knative = array_values(array_filter($this->queries(), fn ($q) => ! str_contains($q, 'traces_span_metrics')));
        $this->assertCount(3, $knative);
        foreach ($knative as $query) {
            $this->assertStringContainsString('namespace_name="'.$region->project_namespace.'",revision_name="'.self::REVISION.'"', $query);
        }
        $this->assertSame('The new revision\'s p95 latency 310 ms over the last 2 min is above the limit of 300 ms.', RolloutMetrics::breach($metrics, ['p95_threshold_ms' => 300]));
        $this->assertNull(RolloutMetrics::breach($metrics, ['error_rate_threshold' => 5, 'p95_threshold_ms' => 500]));
    }

    public function test_an_untraced_project_is_judged_by_knatives_metrics_only(): void
    {
        [, $project] = $this->traced(false);
        $this->answers = ['sum(increase(' => '40', 'histogram_quantile' => '90'];

        $metrics = app(RolloutMetrics::class)->measure($project, 1, self::REVISION);

        $this->assertFalse($metrics['tracing']);
        $this->assertSame(RolloutMetrics::SOURCE_KNATIVE, $metrics['source']);
        $this->assertSame(0.0, $metrics['error_rate'], 'no 5xx series: 0 %');
        $this->assertSame([], array_filter($this->queries(), fn ($q) => str_contains($q, 'traces_span_metrics')), 'no span metrics without tracing');
    }

    public function test_without_requests_of_the_revision_the_canary_moves_on_time_only(): void
    {
        [, $project] = $this->traced();

        $metrics = app(RolloutMetrics::class)->measure($project, 1, self::REVISION);
        $this->assertNull($metrics['source']);
        $this->assertSame(0.0, $metrics['volume']);
        $this->assertNull(RolloutMetrics::breach($metrics, ['error_rate_threshold' => 0]));

        // no revision named: nothing is asked
        $this->k8sCalls = [];
        $this->assertNull(app(RolloutMetrics::class)->measure($project, 1, null)['source']);
        $this->assertSame([], $this->queries());
    }

    public function test_too_few_requests_and_metrics_of_an_earlier_release(): void
    {
        $few = ['source' => RolloutMetrics::SOURCE_KNATIVE, 'error_rate' => 50.0, 'p95_ms' => 9000.0, 'volume' => 3.0, 'window_minutes' => 1];
        $this->assertNull(RolloutMetrics::breach($few, ['error_rate_threshold' => 5]));

        // stored before the source existed: the project's span metrics while tracing was on
        $legacy = ['tracing' => true, 'error_rate' => 12.5, 'p95_ms' => 80.0, 'volume' => 240.0, 'window_minutes' => 1];
        $this->assertNotNull(RolloutMetrics::breach($legacy, ['error_rate_threshold' => 5]));
        $this->assertNull(RolloutMetrics::breach(['tracing' => false] + $legacy, ['error_rate_threshold' => 5]));
    }

    public function test_the_knative_queries_sum_both_metric_names(): void
    {
        $q = RolloutMetrics::knativePromql('fn', 'shop-v2-abc123', 5);
        $sel = 'namespace_name="fn",revision_name="shop-v2-abc123"';

        $this->assertSame("(sum(increase(revision_request_count_total{{$sel}}[5m])) or sum(increase(kn_revision_request_count_total{{$sel}}[5m])))", $q['volume']);
        $this->assertSame("100 * ((sum(rate(revision_request_count_total{{$sel},response_code_class=\"5xx\"}[5m])) or sum(rate(kn_revision_request_count_total{{$sel},response_code_class=\"5xx\"}[5m]))) or vector(0)) / (sum(rate(revision_request_count_total{{$sel}}[5m])) or sum(rate(kn_revision_request_count_total{{$sel}}[5m])))", $q['error_rate']);
        $this->assertSame("histogram_quantile(0.95, (sum by (le)(rate(revision_request_latencies_bucket{{$sel}}[5m])) or sum by (le)(rate(kn_revision_request_latencies_bucket{{$sel}}[5m]))))", $q['p95_latency']);
    }
}
