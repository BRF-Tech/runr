<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\TracingBackendStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * region:reconcile installs or updates the region tracing backend (TracingBackendStep) where
 * tracing is enabled — the region's own mode, or for a region without one the platform's default
 * mode while installWithServing is on — and nowhere else: a region from before RunR had tracing
 * gets the collector and Tempo, a 0.5.0 backend moves to the pinned charts and the reqs mirror
 * images, a current one is left alone.
 */
class TracingBackendReconcileStepTest extends TestCase
{
    use FakesObservabilityCluster, RefreshDatabase;

    private const COLLECTOR = 'ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib:0.162.0';

    private const TEMPO = 'ghcr.io/brf-tech/runr/reqs/tempo:3.1.0';

    /** Live workloads in namespace observability: name => [helm.sh/chart label, image]; absent = not installed */
    private array $collector = [];

    private array $tempo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();

        $this->k8sRoutes['GET /apis/apps/v1/namespaces/observability/deployments/otel-collector'] = fn () => $this->collector
            ? [200, $this->workload('opentelemetry-collector', ...$this->collector)]
            : [404, ['kind' => 'Status', 'code' => 404]];
        $this->k8sRoutes['GET /apis/apps/v1/namespaces/observability/statefulsets/tempo'] = fn () => $this->tempo
            ? [200, $this->workload('tempo', ...$this->tempo)]
            : [404, ['kind' => 'Status', 'code' => 404]];
        $this->k8sRoutes['GET /api/v1/namespaces/knative-serving/configmaps/config-observability'] = fn () => [200, ['kind' => 'ConfigMap']];
    }

    private function workload(string $container, string $chart, string $image): array
    {
        return [
            'metadata' => ['labels' => ['helm.sh/chart' => $chart, 'app.kubernetes.io/managed-by' => 'Helm']],
            'spec' => ['template' => ['spec' => ['containers' => [['name' => $container, 'image' => $image]]]]],
        ];
    }

    private function cluster(KnativeRegion ...$regions): ClusterGroup
    {
        return new ClusterGroup('uid-obs', array_map(fn ($r) => new ReconcileTarget($r), $regions), new KubernetesClient($regions[0]->kubeconfig));
    }

    private function region(array $overrides = []): KnativeRegion
    {
        return $this->obsRegion(array_merge(['knative_serving_status' => 'ready'], $overrides));
    }

    /** @return list<array> the calls that changed the cluster */
    private function k8sWrites(): array
    {
        return array_values(array_filter($this->k8sCalls, fn ($c) => $c['method'] !== 'GET'));
    }

    public function test_a_region_without_tracing_gets_the_backend_of_the_platform_default(): void
    {
        $region = $this->region();

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertSame(["installed the tracing backend of region {$region->name} (mode managed, the platform default: "
            .'observability.tracing.defaultMode with installWithServing): OpenTelemetry Collector '.self::COLLECTOR
            .' (chart opentelemetry-collector-0.176.0); Grafana Tempo '.self::TEMPO.' (chart tempo-3.2.0) in namespace observability, '
            .'Knative sends its spans to the collector; tracing status "installing" until they run'], $lines);

        $this->assertSame(['tempo', 'otel-collector'], array_keys($this->helm->installs));
        $this->assertSame('3.2.0', $this->helm->installs['tempo']['version']);
        $this->assertSame('ghcr.io', $this->helm->installs['tempo']['valuesArray']['tempo']['registry']);
        $this->assertSame('0.176.0', $this->helm->installs['otel-collector']['version']);
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib', $this->helm->installs['otel-collector']['valuesArray']['image']['repository']);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving'),
            'Knative tracing is pointed at the collector');

        $config = RegionTracingConfig::query()->where('region_id', $region->id)->first();
        $this->assertSame(['managed', RegionTracingConfig::STATUS_INSTALLING], [$config?->mode, $config?->status]);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $region = $this->region();

        $lines = (new TracingBackendStep)->run($this->cluster($region), true);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith("would install the tracing backend of region {$region->name} (mode managed, the platform default", $lines[0]);
        $this->assertSame([], $this->helm->installs);
        $this->assertSame([], $this->k8sWrites());
        $this->assertFalse(RegionTracingConfig::query()->where('region_id', $region->id)->exists());
    }

    public function test_nothing_is_installed_where_tracing_is_not_enabled(): void
    {
        // the platform does not install tracing with Serving: regions without their own setting
        config(['observability.tracing.install_with_serving' => false]);
        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($this->region()), false));

        // the platform's default mode is off
        config(['observability.tracing.install_with_serving' => true, 'observability.tracing.default_mode' => 'off']);
        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($this->region()), false));

        // the region's own setting is off, whatever the platform's
        config(['observability.tracing.default_mode' => 'managed']);
        $off = $this->region();
        RegionTracingConfig::forRegion($off)->fill(['mode' => 'off'])->save();
        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($off), false));

        $this->assertSame([], $this->helm->installs);
        $this->assertSame([], $this->k8sCalls, 'not even read');
    }

    public function test_the_regions_own_setting_enables_it_without_the_platform_default(): void
    {
        config(['observability.tracing.install_with_serving' => false]);
        $region = $this->region();
        RegionTracingConfig::forRegion($region)->fill(['mode' => 'external', 'export_endpoint' => 'https://otlp.example.com'])->save();

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertStringStartsWith("installed the tracing backend of region {$region->name} (mode external, the region's setting): "
            .'OpenTelemetry Collector '.self::COLLECTOR.' (chart opentelemetry-collector-0.176.0) in namespace observability', $lines[0]);
        $this->assertSame(['otel-collector'], array_keys($this->helm->installs), 'no Tempo in mode external');
    }

    public function test_a_0_5_0_backend_moves_to_the_pinned_charts_and_the_mirror_images(): void
    {
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.175.1', 'otel/opentelemetry-collector-contrib:0.161.0'];
        $this->tempo = ['tempo-3.1.0', 'docker.io/grafana/tempo:3.1.0'];

        $dry = (new TracingBackendStep)->run($this->cluster($region), true);
        $update = "the tracing backend of region {$region->name} (mode managed, the region's setting): "
            .'OpenTelemetry Collector chart opentelemetry-collector 0.175.1 → 0.176.0, image otel/opentelemetry-collector-contrib:0.161.0 → '.self::COLLECTOR
            .'; Grafana Tempo chart tempo 3.1.0 → 3.2.0, image docker.io/grafana/tempo:3.1.0 → '.self::TEMPO
            .' — the collector and Tempo pods roll once, Tempo keeps its traces';
        $this->assertSame(["would update {$update}"], $dry);
        $this->assertSame([], $this->helm->installs);

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertSame(["updated {$update}; tracing status \"installing\" until they run"], $lines);
        $this->assertSame(['3.2.0', '0.176.0'], [$this->helm->installs['tempo']['version'], $this->helm->installs['otel-collector']['version']]);
        $this->assertSame('72h', $this->helm->installs['tempo']['valuesArray']['tempo']['retention']);
    }

    public function test_a_current_backend_is_left_alone(): void
    {
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];
        $this->tempo = ['tempo-3.2.0', self::TEMPO];

        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->helm->installs);
        $this->assertSame([], $this->k8sWrites());

        // the image under another spelling of the same reference is the same image
        config(['observability.tracing.tempo_image' => 'grafana/tempo:3.1.0']);
        $this->tempo = ['tempo-3.2.0', 'docker.io/grafana/tempo:3.1.0'];
        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($region), false));
    }

    public function test_reuse_mode_needs_only_the_collector(): void
    {
        $region = $this->region();
        $this->tracingReady($region, ['mode' => 'reuse', 'tempo_otlp_endpoint' => 'tempo-distributor.monitoring.svc:4317']);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];

        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($region), false), 'no Tempo of its own in mode reuse');

        $this->collector = ['opentelemetry-collector-0.175.1', 'otel/opentelemetry-collector-contrib:0.161.0'];
        $lines = (new TracingBackendStep)->run($this->cluster($region), false);
        $this->assertStringEndsWith(' — the collector pod rolls once; tracing status "installing" until it runs', $lines[0]);
        $this->assertSame(['otel-collector'], array_keys($this->helm->installs));
    }

    public function test_a_missing_tempo_is_installed_next_to_a_current_collector(): void
    {
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];

        $lines = (new TracingBackendStep)->run($this->cluster($region), true);

        $this->assertSame(["would update the tracing backend of region {$region->name} (mode managed, the region's setting): "
            .'Grafana Tempo not installed → '.self::TEMPO.' (chart tempo-3.2.0) — the collector and Tempo pods roll once, Tempo keeps its traces'], $lines);
    }

    public function test_configured_images_win(): void
    {
        config(['observability.tracing.collector_image' => 'registry.example.com/mirror/otelcol-contrib:0.162.0']);
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];
        $this->tempo = ['tempo-3.2.0', self::TEMPO];

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertStringContainsString('OpenTelemetry Collector image '.self::COLLECTOR.' → registry.example.com/mirror/otelcol-contrib:0.162.0', $lines[0]);
        $this->assertSame(['repository' => 'registry.example.com/mirror/otelcol-contrib', 'tag' => '0.162.0'], $this->helm->installs['otel-collector']['valuesArray']['image']);
    }

    public function test_the_first_region_of_the_cluster_with_tracing_enabled_decides(): void
    {
        $off = $this->region();
        RegionTracingConfig::forRegion($off)->fill(['mode' => 'off'])->save();
        $sibling = $this->region();

        $lines = (new TracingBackendStep)->run($this->cluster($off, $sibling), false);

        $this->assertStringStartsWith("installed the tracing backend of region {$sibling->name} (mode managed, the platform default", $lines[0]);
        $this->assertSame(['tempo', 'otel-collector'], array_keys($this->helm->installs));
        $this->assertSame('managed', RegionTracingConfig::forRegion($sibling)->mode);
        $this->assertSame('off', RegionTracingConfig::forRegion($off)->mode);
    }

    public function test_a_failing_install_fails_the_step(): void
    {
        $region = $this->region();
        $this->helm->fail = true;

        try {
            (new TracingBackendStep)->run($this->cluster($region), false);
            $this->fail('the step did not fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Tempo: boom', $e->getMessage());
        }

        $this->assertSame(RegionTracingConfig::STATUS_ERROR, RegionTracingConfig::forRegion($region)->status);
    }

    public function test_step_is_part_of_region_reconcile(): void
    {
        $steps = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        $this->assertContains('tracing', $steps);
        $this->assertSame(array_search('loki-deletion', $steps) + 1, array_search('tracing', $steps));
    }
}
