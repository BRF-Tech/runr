<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\KnativeMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Live metrics of a deployment (KnativeMetricsService::getDeploymentMetrics) from the region's
 * Prometheus through the API service proxy: the PromQL per project type and trigger (handler /
 * app container, the CronJob pods and a longer window for scheduled functions, the runr:*
 * recording rules and hourly averages for the full set), Knative's request count with the
 * legacy metric as fallback, rounding, the cached answer and an unreachable Prometheus.
 */
class DeploymentMetricsTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private const PROXY = '/api/v1/namespaces/observability/services/http:prometheus-operated:9090/proxy/api/v1/query';

    private KnativeRegion $region;

    /** @var list<string> PromQL the fake Prometheus was asked */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        // not running in a pod: never "RunR's own cluster", always the API service proxy
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        $this->region = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A Prometheus answering every query with the value of the first matching metric name
     * (no series for the others).
     *
     * @param  array<string, string>  $values  metric name → sample value
     */
    private function prometheus(array $values, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($values, $status) {
            $this->assertStringContainsString(self::PROXY, $request->url());
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->queries[] = $promql = (string) $query['query'];
            if ($status !== 200) {
                return Http::response('upstream connect error', $status);
            }
            foreach ($values as $metric => $value) {
                if (str_contains($promql, $metric)) {
                    return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1760529600, $value]]]]]);
                }
            }

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
        });
    }

    private function deployed(string $type = 'faas', string $trigger = 'http'): array
    {
        $project = Project::create(['name' => 'orders', 'service_type' => $type]);
        $project->forceFill(['slug' => 'orders', 'knative_region_id' => $this->region->id])->saveQuietly();
        $type === 'caas'
            ? ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => $trigger])
            : FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready', 'config_snapshot' => ['trigger_type' => $trigger]]);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);

        return [$project->fresh(), $deployment];
    }

    private function query(string $metric): string
    {
        return collect($this->queries)->first(fn (string $q) => str_contains($q, $metric)) ?? '';
    }

    public function test_quick_metrics_of_a_function_and_their_cached_answer(): void
    {
        $this->prometheus([
            'container_cpu_usage_seconds_total' => '0.123456',
            'container_memory_working_set_bytes' => '64.504',
            '(revision_request_count_total' => '2.34567',  // the legacy name; Knative's own has no series
        ]);
        [$project, $deployment] = $this->deployed();

        $metrics = app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment, true);

        $this->assertSame(['cpu' => 0.1235, 'memory' => 64.5, 'rps' => 2.346], $metrics);
        $this->assertCount(4, $this->queries, 'cpu, memory and both request counts in parallel');
        $this->assertSame(
            'sum(last_over_time(rate(container_cpu_usage_seconds_total{namespace="fn",pod=~"orders-[0-9]+-deployment-.*",container="handler"}[30s])[30s:5s]))',
            $this->query('container_cpu_usage_seconds_total'),
        );
        $this->assertSame('sum(rate(kn_revision_request_count_total{namespace_name="fn",service_name="orders"}[1m]))', $this->query('kn_revision_request_count_total'));

        // the same second: answered from the cache, Prometheus is not asked again
        $this->queries = [];
        $this->assertSame($metrics, app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment, true));
        $this->assertSame([], $this->queries);
    }

    public function test_knatives_request_count_wins_over_the_legacy_one(): void
    {
        $this->prometheus(['kn_revision_request_count_total' => '7', '(revision_request_count_total' => '3']);
        [$project, $deployment] = $this->deployed();

        $metrics = app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment, true);

        $this->assertSame(7.0, $metrics['rps']);
        $this->assertNull($metrics['cpu']);
        $this->assertNull($metrics['memory']);
    }

    public function test_a_scheduled_container_is_read_from_its_cronjob_pods_over_six_minutes(): void
    {
        $this->prometheus([]);
        [$project, $deployment] = $this->deployed('caas', 'schedule');

        app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment, true);

        $this->assertSame(
            'sum(last_over_time(container_memory_working_set_bytes{namespace="fn",pod=~"orders-trigger-.*",container="app"}[6m])) / 1048576',
            $this->query('container_memory_working_set_bytes'),
        );
    }

    public function test_full_metrics_use_the_recording_rules_and_hourly_averages(): void
    {
        $this->prometheus([
            'runr:cpu_cores:rate2m' => '0.5',
            'runr:memory_mb:current' => '256.789',
            'runr:cpu_cores:avg1h' => '0.250049',
            'runr:memory_mb:avg1h' => '200.001',
            'kn_revision_request_count_total' => '12.3456',
        ]);
        [$project, $deployment] = $this->deployed('caas');

        $metrics = app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment);

        $this->assertSame(['cpu' => 0.5, 'memory' => 256.79, 'rps' => 12.346, 'cpuAvg1h' => 0.25, 'memAvg1h' => 200.0], $metrics);
        $this->assertCount(6, $this->queries);
        // The revisions' pods only: an environment's (orders-staging-…) or a preview's are not the project's
        $this->assertSame('sum(last_over_time(runr:cpu_cores:avg1h{namespace="fn",pod=~"orders-[0-9]+-deployment-.*",container="app"}[3m]))', $this->query('runr:cpu_cores:avg1h'));

        // cached for a minute
        $this->queries = [];
        Carbon::setTestNow('2026-10-15 12:00:30');
        $this->assertSame($metrics, app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment));
        $this->assertSame([], $this->queries);
    }

    public function test_an_unreachable_prometheus_gives_no_values(): void
    {
        $this->prometheus([], 503);
        [$project, $deployment] = $this->deployed();

        $this->assertSame(['cpu' => null, 'memory' => null, 'rps' => null], app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment, true));
    }

    public function test_a_project_without_a_region_has_no_metrics(): void
    {
        Http::fake();
        [$project, $deployment] = $this->deployed();
        $project->setRelation('knativeRegion', null);

        $this->assertSame(['cpu' => null, 'memory' => null, 'rps' => null], app(KnativeMetricsService::class)->getDeploymentMetrics($project, $deployment, true));
        Http::assertNothingSent();
    }
}
