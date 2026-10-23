<?php

namespace Tests\Unit\Rightsizing;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Rightsizing\ResourcePeakQuery;
use Tests\TestCase;

/**
 * The one Prometheus query behind a day of peaks: the project's own workload container only
 * (revision pods of its Knative Service or its CronJob's pods, never previews, the queue-proxy or
 * another project whose slug starts the same), every statistic marked with runr_stat, and the
 * answer read back with NaN (0 / 0 throttled periods) as missing.
 */
class ResourcePeakQueryTest extends TestCase
{
    private function project(string $slug, string $type = 'faas'): Project
    {
        return (new Project)->forceFill(['slug' => $slug, 'service_type' => $type]);
    }

    private function region(?string $namespace = 'fn'): KnativeRegion
    {
        return (new KnativeRegion)->forceFill(['name' => 'eu-1', 'project_namespace' => $namespace]);
    }

    public function test_the_selector_reads_the_workload_container_of_the_project(): void
    {
        $this->assertSame(
            'namespace="fn",pod=~"orders-[0-9]+-deployment-.+",container="handler"',
            ResourcePeakQuery::selector($this->region(), $this->project('orders'), 'http'),
        );
        $this->assertSame(
            'namespace="fn",pod=~"shop-[0-9]+-deployment-.+",container="app"',
            ResourcePeakQuery::selector($this->region(), $this->project('shop', 'caas'), 'queue'),
        );
        $this->assertSame(
            'namespace="eu-1",pod=~"nightly-trigger-.+",container="handler"',
            ResourcePeakQuery::selector($this->region(null), $this->project('nightly', 'xaas'), 'schedule'),
            'a CronJob\'s pods; the region name when it has no project namespace',
        );

        // Fully anchored: neither a preview (orders-pr-3-00001-…) nor the project "orders-2" matches
        $pattern = '/^orders-[0-9]+-deployment-.+$/';
        $this->assertMatchesRegularExpression($pattern, 'orders-00004-deployment-6f7d9c5b8-x2k4p');
        $this->assertDoesNotMatchRegularExpression($pattern, 'orders-pr-3-00001-deployment-6f7d9c5b8-x2k4p');
        $this->assertDoesNotMatchRegularExpression($pattern, 'orders-2-00001-deployment-6f7d9c5b8-x2k4p');
    }

    public function test_one_query_answers_every_statistic(): void
    {
        $promql = ResourcePeakQuery::promql('namespace="fn",pod=~"orders-[0-9]+-deployment-.+",container="handler"');

        foreach (['memory_max', 'memory_p95', 'cpu_max', 'cpu_p95', 'throttled', 'oom_events', 'oom_restarts', 'samples', 'pods'] as $stat) {
            $this->assertStringContainsString("\"runr_stat\", \"{$stat}\"", $promql);
        }
        $this->assertSame(8, substr_count($promql, ' or '));
        $this->assertStringContainsString('max(max by (pod) (max_over_time(container_memory_working_set_bytes{namespace="fn",pod=~"orders-[0-9]+-deployment-.+",container="handler"}[1d]))) / 1048576', $promql);
        $this->assertStringContainsString('quantile_over_time(0.95, rate(container_cpu_usage_seconds_total{', $promql);
        $this->assertStringContainsString('[1m])[1d:1m]', $promql);
        $this->assertStringContainsString('container_cpu_cfs_throttled_periods_total', $promql);
        $this->assertStringContainsString('reason="OOMKilled"', $promql);
        // balanced parentheses
        $this->assertSame(substr_count($promql, '('), substr_count($promql, ')'));
    }

    public function test_the_answer_is_read_by_statistic(): void
    {
        $stats = ResourcePeakQuery::parse([
            ['metric' => ['runr_stat' => 'memory_max'], 'value' => [1760000000, '141.5']],
            ['metric' => ['runr_stat' => 'memory_p95'], 'value' => [1760000000, '120.25']],
            ['metric' => ['runr_stat' => 'cpu_p95'], 'value' => [1760000000, '84.2']],
            ['metric' => ['runr_stat' => 'throttled'], 'value' => [1760000000, 'NaN']],
            ['metric' => ['runr_stat' => 'oom_events'], 'value' => [1760000000, '1']],
            ['metric' => ['runr_stat' => 'samples'], 'value' => [1760000000, '86400']],
            ['metric' => ['runr_stat' => 'pods'], 'value' => [1760000000, '12']],
            ['metric' => ['runr_stat' => 'memory_max'], 'value' => [1760000000, '99']],   // a duplicate series: the higher one
            ['metric' => ['other' => 'x'], 'value' => [1760000000, '5']],
        ]);

        $this->assertSame(141.5, $stats['memory_max']);
        $this->assertSame(120.25, $stats['memory_p95']);
        $this->assertNull($stats['cpu_max']);
        $this->assertSame(84.2, $stats['cpu_p95']);
        $this->assertNull($stats['throttled'], 'no CFS periods: 0 / 0');
        $this->assertSame(1.0, $stats['oom_events']);
        $this->assertNull($stats['oom_restarts'], 'no kube-state-metrics');
        $this->assertSame(86400.0, $stats['samples']);
        $this->assertSame(12.0, $stats['pods']);
        $this->assertSame([], array_diff_key($stats, array_flip(['memory_max', 'memory_p95', 'cpu_max', 'cpu_p95', 'throttled', 'oom_events', 'oom_restarts', 'samples', 'pods'])));
    }
}
