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
use Illuminate\Support\Sleep;
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

    /** Requests of the live chart containers and Tempo's init containers: this release's unless a test changes them */
    private array $collectorRequests = ['cpu' => '50m', 'memory' => '128Mi'];

    private array $tempoRequests = ['cpu' => '50m', 'memory' => '256Mi'];

    private array $tempoInit = ['fix-permissions'];

    /** Generation and status of the live Tempo StatefulSet, and its pods */
    private array $tempoState = [];

    private array $tempoPods = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();

        $this->k8sRoutes['GET /apis/apps/v1/namespaces/observability/deployments/otel-collector'] = fn () => $this->collector
            ? [200, $this->workload('opentelemetry-collector', ...$this->collector, requests: $this->collectorRequests)]
            : [404, ['kind' => 'Status', 'code' => 404]];
        $this->k8sRoutes['GET /apis/apps/v1/namespaces/observability/statefulsets/tempo'] = fn () => $this->tempo
            ? [200, array_replace_recursive($this->workload('tempo', ...$this->tempo, requests: $this->tempoRequests, init: $this->tempoInit), $this->tempoState)]
            : [404, ['kind' => 'Status', 'code' => 404]];
        $this->k8sRoutes['GET /api/v1/namespaces/observability/pods'] = fn () => [200, ['items' => $this->tempoPods]];
        $this->k8sRoutes['GET /api/v1/namespaces/knative-serving/configmaps/config-observability'] = fn () => [200, ['kind' => 'ConfigMap']];
    }

    /**
     * @param  list<string>  $init  names of the init containers
     */
    private function workload(string $container, string $chart, string $image, array $requests = [], array $init = []): array
    {
        return [
            'metadata' => ['labels' => ['helm.sh/chart' => $chart, 'app.kubernetes.io/managed-by' => 'Helm']],
            'spec' => ['template' => ['spec' => array_filter([
                'initContainers' => array_map(fn (string $name) => ['name' => $name, 'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest'], $init),
                'containers' => [['name' => $container, 'image' => $image, 'resources' => ['requests' => $requests]]],
            ])]],
        ];
    }

    /** A pod of the Tempo StatefulSet: of revision $revision, ready or crash-looping */
    private function tempoPod(string $name, string $revision, bool $ready): array
    {
        return [
            'metadata' => ['name' => $name, 'labels' => [
                'app.kubernetes.io/name' => 'tempo', 'app.kubernetes.io/instance' => 'tempo', 'controller-revision-hash' => $revision,
            ]],
            'status' => [
                'phase' => 'Running',
                'conditions' => [['type' => 'Ready', 'status' => $ready ? 'True' : 'False']],
                'containerStatuses' => [[
                    'name' => 'tempo', 'ready' => $ready, 'restartCount' => $ready ? 0 : 7,
                    'state' => $ready ? ['running' => []] : ['waiting' => ['reason' => 'CrashLoopBackOff']],
                ]],
            ],
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
        // the requests up to 0.5.2, no fix-permissions init container
        $this->collectorRequests = ['cpu' => '100m', 'memory' => '128Mi'];
        $this->tempoRequests = ['cpu' => '100m', 'memory' => '256Mi'];
        $this->tempoInit = [];

        $dry = (new TracingBackendStep)->run($this->cluster($region), true);
        $update = "the tracing backend of region {$region->name} (mode managed, the region's setting): "
            .'OpenTelemetry Collector chart opentelemetry-collector 0.175.1 → 0.176.0, image otel/opentelemetry-collector-contrib:0.161.0 → '.self::COLLECTOR
            .', cpu request 100m → 50m'
            .'; Grafana Tempo chart tempo 3.1.0 → 3.2.0, image docker.io/grafana/tempo:3.1.0 → '.self::TEMPO
            .', cpu request 100m → 50m, init container fix-permissions (chown of /var/tempo) added'
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

    /**
     * Storage drivers that don't apply fsGroup left Tempo's volume owned by root: Tempo crash-looped
     * on "mkdir /var/tempo/traces: permission denied". A 0.5.2 Tempo gets the init container that
     * hands the volume to Tempo's user, as Loki's has, with the next reconcile.
     */
    public function test_a_tempo_without_the_fix_permissions_init_container_gets_it(): void
    {
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];
        $this->tempo = ['tempo-3.2.0', self::TEMPO];
        $this->tempoInit = [];

        $update = "the tracing backend of region {$region->name} (mode managed, the region's setting): "
            .'Grafana Tempo init container fix-permissions (chown of /var/tempo) added — the collector and Tempo pods roll once, Tempo keeps its traces';
        $this->assertSame(["would update {$update}"], (new TracingBackendStep)->run($this->cluster($region), true));
        $this->assertSame([], $this->helm->installs);

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertSame(["updated {$update}; tracing status \"installing\" until they run"], $lines);
        $this->assertSame([[
            'name' => 'fix-permissions',
            'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
            'command' => ['sh', '-c', 'chown -R 10001:10001 /var/tempo'],
            'volumeMounts' => [['name' => 'storage', 'mountPath' => '/var/tempo']],
            'securityContext' => ['runAsUser' => 0, 'runAsNonRoot' => false],
            'resources' => ['requests' => ['cpu' => '10m', 'memory' => '16Mi'], 'limits' => ['memory' => '32Mi']],
        ]], $this->helm->installs['tempo']['valuesArray']['initContainers']);

        // once it runs with the init container: up to date
        $this->helm->installs = [];
        $this->tempoInit = ['fix-permissions'];
        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($region), false));
        $this->assertSame([], $this->helm->installs);
    }

    /**
     * A crash-looping Tempo (its volume not writable) holds up its StatefulSet's rolling update:
     * with the chart's pod management (OrderedReady) the controller waits for the pod to become
     * ready before it replaces it. The install deletes such a pod once the controller has seen
     * the new template, and a reconcile that finds one left replaces it as well.
     */
    public function test_a_crash_looping_tempo_pod_of_an_earlier_template_is_replaced(): void
    {
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];
        $this->tempo = ['tempo-3.2.0', self::TEMPO];
        $this->tempoState = ['metadata' => ['generation' => 4], 'status' => ['observedGeneration' => 4, 'updateRevision' => 'tempo-7c9d5']];
        $this->tempoPods = [$this->tempoPod('tempo-0', 'tempo-5f8b6', false)];

        $update = "the tracing backend of region {$region->name} (mode managed, the region's setting): "
            .'Grafana Tempo pod tempo-0 not ready with an earlier template (holds up the update: replaced) — the collector and Tempo pods roll once, Tempo keeps its traces';
        $this->assertSame(["would update {$update}"], (new TracingBackendStep)->run($this->cluster($region), true));
        $this->assertSame([], $this->k8sWrites());

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertSame(["updated {$update}; tracing status \"installing\" until they run"], $lines);
        $this->assertArrayHasKey('tempo', $this->helm->installs);
        $this->assertCount(1, $this->k8sCallsTo('DELETE', '/api/v1/namespaces/observability/pods/tempo-0'));

        // nothing holds up the update: a ready pod of the earlier template (the controller replaces
        // it), a pod of the current template, a pod being deleted, a template not yet observed
        $pods = [
            [$this->tempoPod('tempo-0', 'tempo-5f8b6', true)],
            [$this->tempoPod('tempo-0', 'tempo-7c9d5', false)],
            [array_replace_recursive($this->tempoPod('tempo-0', 'tempo-5f8b6', false), ['metadata' => ['deletionTimestamp' => '2026-10-16T10:00:00Z']])],
        ];
        foreach ($pods as $i => $tempoPods) {
            $this->tempoPods = $tempoPods;
            $this->assertSame([], (new TracingBackendStep)->run($this->cluster($region), false), "case {$i}");
        }
        $this->tempoPods = [$this->tempoPod('tempo-0', 'tempo-5f8b6', false)];
        $this->tempoState['status']['observedGeneration'] = 3;
        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($region), false));
        $this->assertCount(1, $this->k8sCallsTo('DELETE', '/api/v1/namespaces/observability/pods/'), 'no other deletion');
    }

    /**
     * Right after the Helm upgrade the controller may not have seen Tempo's new template: the
     * install waits for it, 4.5 s at most, and replaces no pod while it is not seen.
     */
    public function test_the_install_waits_briefly_for_the_controller_to_see_the_template(): void
    {
        Sleep::fake();
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];
        $this->tempo = ['tempo-3.2.0', self::TEMPO];
        $this->tempoInit = [];
        $this->tempoState = ['metadata' => ['generation' => 4], 'status' => ['observedGeneration' => 3, 'updateRevision' => 'tempo-5f8b6']];
        $this->tempoPods = [$this->tempoPod('tempo-0', 'tempo-1a2b3', false)];

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertStringContainsString('Grafana Tempo init container fix-permissions (chown of /var/tempo) added', $lines[0]);
        Sleep::assertSleptTimes(9);
        $this->assertSame([], $this->k8sCallsTo('DELETE', '/api/v1/namespaces/observability/pods/'));
    }

    /**
     * A single-node region ran short of CPU once tracing was installed (Knative's own pods stayed
     * Pending on "Insufficient cpu"): the collector and Tempo request what the other region
     * add-ons do, and installed backends move to that.
     */
    public function test_requests_above_this_releases_are_lowered(): void
    {
        $region = $this->region();
        $this->tracingReady($region);
        $this->collector = ['opentelemetry-collector-0.176.0', self::COLLECTOR];
        $this->tempo = ['tempo-3.2.0', self::TEMPO];
        $this->collectorRequests = ['cpu' => '100m', 'memory' => '128Mi'];

        $lines = (new TracingBackendStep)->run($this->cluster($region), false);

        $this->assertSame(["updated the tracing backend of region {$region->name} (mode managed, the region's setting): "
            .'OpenTelemetry Collector cpu request 100m → 50m — the collector and Tempo pods roll once, Tempo keeps its traces; '
            .'tracing status "installing" until they run'], $lines);
        $this->assertSame(['cpu' => '50m', 'memory' => '128Mi'], $this->helm->installs['otel-collector']['valuesArray']['resources']['requests']);
        $this->assertSame(['cpu' => '50m', 'memory' => '256Mi'], $this->helm->installs['tempo']['valuesArray']['tempo']['resources']['requests']);

        // the same quantities in another spelling are the same requests; a missing one is a change
        $this->helm->installs = [];
        $this->collectorRequests = ['cpu' => '0.05', 'memory' => (string) (128 * 1048576)];
        $this->assertSame([], (new TracingBackendStep)->run($this->cluster($region), true));
        $this->tempoRequests = ['cpu' => '50m'];
        $this->assertStringContainsString('Grafana Tempo memory request (none) → 256Mi', (new TracingBackendStep)->run($this->cluster($region), true)[0]);
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
