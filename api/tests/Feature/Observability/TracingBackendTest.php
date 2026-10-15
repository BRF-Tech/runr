<?php

namespace Tests\Feature\Observability;

use App\Jobs\InstallTracingJob;
use App\Models\Observability\RegionTracingConfig;
use App\Services\KubernetesClient;
use App\Services\Observability\TracingInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Region tracing backend: the installer (Tempo + collector Helm releases, PriorityClass,
 * PodMonitor, Knative tracing through the KnativeServing CR and its ConfigMaps), modes reuse /
 * external / off, the installing → ready status, the Serving hook that never fails, and the
 * region tracing API (validation, write-only export headers, permissions, region.mutable).
 */
class TracingBackendTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();

        // the Prometheus Operator is installed, config-observability / config-tracing exist
        $this->k8sRoutes['GET /apis/apiextensions.k8s.io/v1/customresourcedefinitions/podmonitors.monitoring.coreos.com'] = fn () => [200, ['kind' => 'CustomResourceDefinition']];
        $this->k8sRoutes['GET /api/v1/namespaces/observability'] = fn () => [200, ['kind' => 'Namespace']];
        $this->k8sRoutes['GET /api/v1/namespaces/knative-serving/configmaps/config-observability'] = fn () => [200, ['kind' => 'ConfigMap']];
        $this->k8sRoutes['GET /api/v1/namespaces/knative-serving/configmaps/config-tracing'] = fn () => [200, ['kind' => 'ConfigMap']];
    }

    public function test_managed_install_runs_tempo_and_the_collector_and_points_knative_at_it(): void
    {
        $region = $this->obsRegion(['registry_storage_config' => ['storageClass' => 'fast']]);

        $config = app(TracingInstallService::class)->install($region);

        $this->assertSame(RegionTracingConfig::STATUS_INSTALLING, $config->status);
        $this->assertSame('http://tempo.observability.svc.cluster.local:3200', $config->tempo_url);
        $this->assertNotNull($config->installed_at);

        $this->assertSame(['tempo', 'otel-collector'], array_keys($this->helm->installs));
        $tempo = $this->helm->installs['tempo'];
        $this->assertSame(['grafana-community/tempo', 'observability', false], [$tempo['chart'], $tempo['namespace'], $tempo['wait']]);
        $this->assertSame('3.1.0', $tempo['version'], 'pinned chart (config observability.tracing.tempo_chart_version)');
        $this->assertSame('fast', $tempo['valuesArray']['persistence']['storageClassName']);
        $collector = $this->helm->installs['otel-collector'];
        $this->assertSame('open-telemetry/opentelemetry-collector', $collector['chart']);
        $this->assertSame('0.175.1', $collector['version'], 'pinned chart (config observability.tracing.collector_chart_version)');
        $this->assertSame('tempo.observability.svc.cluster.local:4317', $collector['valuesArray']['config']['exporters']['otlp/tempo']['endpoint']);
        $this->assertSame(['grafana-community', 'open-telemetry'], array_keys($this->helm->repos));
        $this->assertSame('https://grafana-community.github.io/helm-charts', $this->helm->repos['grafana-community']);

        // PriorityClass (cluster-wide) and the span-metrics PodMonitor
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/apis/scheduling.k8s.io/v1/priorityclasses/observability-critical'));
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/apis/monitoring.coreos.com/v1/namespaces/observability/podmonitors/otel-collector-spanmetrics'));

        // Knative: the CR (operator keeps it) and the ConfigMaps (applies at once)
        $cr = $this->k8sCallsTo('PATCH', '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving')[0]['body'];
        $this->assertSame('http/protobuf', $cr['spec']['config']['observability']['tracing-protocol']);
        $this->assertSame('0.1', $cr['spec']['config']['observability']['tracing-sampling-rate']);
        $this->assertSame('zipkin', $cr['spec']['config']['tracing']['backend']);
        $observability = $this->k8sCallsTo('PATCH', '/api/v1/namespaces/knative-serving/configmaps/config-observability')[0]['body'];
        $this->assertSame('prometheus', $observability['data']['metrics-protocol']);
        $this->assertSame('http://otel-collector.observability.svc.cluster.local:4318/v1/traces', $observability['data']['tracing-endpoint']);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/api/v1/namespaces/knative-serving/configmaps/config-tracing'));
    }

    public function test_reuse_and_external_install_only_the_collector(): void
    {
        $region = $this->obsRegion();
        RegionTracingConfig::forRegion($region)->fill([
            'mode' => 'external', 'export_endpoint' => 'https://otlp.example.com', 'export_headers' => ['Authorization' => 'Bearer secret-token'],
        ])->save();

        app(TracingInstallService::class)->install($region);

        $this->assertSame(['otel-collector'], array_keys($this->helm->installs));
        $secret = $this->k8sCallsTo('PATCH', '/api/v1/namespaces/observability/secrets/otel-collector-export')[0]['body'];
        $this->assertSame(base64_encode('Bearer secret-token'), $secret['data']['otlp_header_0']);
        $this->assertStringNotContainsString('secret-token', json_encode($this->helm->installs));

        $this->helm->installs = [];
        $reuse = $this->obsRegion();
        RegionTracingConfig::forRegion($reuse)->fill([
            'mode' => 'reuse', 'tempo_url' => 'http://tempo-query.monitoring.svc:3200', 'tempo_otlp_endpoint' => 'tempo-distributor.monitoring.svc:4317',
        ])->save();
        $config = app(TracingInstallService::class)->install($reuse);

        $this->assertSame(['otel-collector'], array_keys($this->helm->installs));
        $this->assertSame('http://tempo-query.monitoring.svc:3200', $config->tempo_url, 'the reused Tempo is queried');
    }

    public function test_mode_off_disables_knative_tracing_and_installs_nothing(): void
    {
        $region = $this->obsRegion();
        RegionTracingConfig::forRegion($region)->fill(['mode' => 'off'])->save();

        $config = app(TracingInstallService::class)->install($region);

        $this->assertNull($config->status);
        $this->assertSame([], $this->helm->installs);
        $cr = $this->k8sCallsTo('PATCH', '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving')[0]['body'];
        $this->assertSame('none', $cr['spec']['config']['observability']['tracing-protocol']);
    }

    public function test_status_becomes_ready_when_the_pods_run(): void
    {
        $region = $this->obsRegion();
        RegionTracingConfig::forRegion($region)->fill(['mode' => 'managed', 'status' => 'installing'])->save();

        $pods = ['collector' => 'Pending', 'tempo' => 'Running'];
        $this->k8sRoutes['GET /api/v1/namespaces/observability/pods'] = function ($request) use (&$pods) {
            $selector = (string) ($this->k8sCalls[array_key_last($this->k8sCalls)]['query']['labelSelector'] ?? '');
            $phase = str_contains($selector, 'tempo') ? $pods['tempo'] : $pods['collector'];

            return [200, ['items' => [['status' => ['phase' => $phase, 'containerStatuses' => [['ready' => $phase === 'Running']]]]]]];
        };

        $service = app(TracingInstallService::class);
        $this->assertSame('installing', $service->refreshStatus($region)->status);

        $pods['collector'] = 'Running';
        $this->assertSame('ready', $service->refreshStatus($region)->status);
        $this->assertTrue(RegionTracingConfig::forRegion($region)->acceptsSpans());
        $this->assertTrue(RegionTracingConfig::forRegion($region)->isQueryable());
    }

    public function test_a_failing_install_is_stored_and_never_fails_serving(): void
    {
        $region = $this->obsRegion();
        $this->helm->fail = true;

        $logged = [];
        app(TracingInstallService::class)->installWithServing($region, new KubernetesClient($region->kubeconfig), $this->helm, function ($m) use (&$logged) {
            $logged[] = $m;
        });

        $config = RegionTracingConfig::forRegion($region);
        $this->assertSame('error', $config->status);
        $this->assertStringContainsString('boom', (string) $config->status_message);
        $this->assertStringContainsString('Tracing backend install failed', implode("\n", $logged));
    }

    public function test_install_with_serving_can_be_switched_off(): void
    {
        config(['observability.tracing.install_with_serving' => false]);
        $region = $this->obsRegion();

        app(TracingInstallService::class)->installWithServing($region, new KubernetesClient($region->kubeconfig), $this->helm);

        $this->assertSame([], $this->helm->installs);
        $this->assertFalse(RegionTracingConfig::query()->where('region_id', $region->id)->exists());
    }

    public function test_region_tracing_api(): void
    {
        Bus::fake();
        $owner = $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $region = $this->obsRegion();

        $this->signedIn($viewer)->getJson("/api/observability/regions/{$region->id}/tracing")
            ->assertOk()
            ->assertJsonPath('data.mode', 'managed')
            ->assertJsonPath('data.status', null);

        $this->signedIn($viewer)->putJson("/api/observability/regions/{$region->id}/tracing", ['mode' => 'off'])
            ->assertForbidden()
            ->assertJsonPath('permissions', ['regions.observability.manage']);

        // reuse needs an in-cluster Tempo; external an https endpoint
        $this->signedIn($owner)->putJson("/api/observability/regions/{$region->id}/tracing", ['mode' => 'reuse', 'tempo_url' => 'http://169.254.169.254/'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tempo_url', 'tempo_otlp_endpoint']);
        $this->signedIn($owner)->putJson("/api/observability/regions/{$region->id}/tracing", ['mode' => 'external', 'export_endpoint' => 'http://plain.example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['export_endpoint']);

        $this->signedIn($owner)->putJson("/api/observability/regions/{$region->id}/tracing", [
            'mode' => 'external', 'export_endpoint' => 'https://otlp.example.com', 'export_headers' => ['Authorization' => 'Bearer abc'],
            'knative_sample_rate' => 0.5, 'install' => true,
        ])->assertStatus(202)
            ->assertJsonPath('data.mode', 'external')
            ->assertJsonPath('data.status', 'installing')
            ->assertJsonPath('data.export_header_names', ['Authorization'])
            ->assertJsonMissingPath('data.export_headers');
        Bus::assertDispatched(InstallTracingJob::class, fn ($job) => $job->regionId === $region->id && ! $job->uninstall);

        // export headers are write-only: omitted → kept
        $this->signedIn($owner)->putJson("/api/observability/regions/{$region->id}/tracing", ['mode' => 'external', 'export_endpoint' => 'https://otlp.example.com'])
            ->assertOk();
        $this->assertSame(['Authorization' => 'Bearer abc'], RegionTracingConfig::forRegion($region)->export_headers);
        $this->assertStringNotContainsString('Bearer abc', (string) json_encode(RegionTracingConfig::forRegion($region)->toArray()));

        $this->signedIn($owner)->deleteJson("/api/observability/regions/{$region->id}/tracing")->assertStatus(202);
        Bus::assertDispatched(InstallTracingJob::class, fn ($job) => $job->uninstall);
    }

    public function test_uninstall_removes_the_releases(): void
    {
        $region = $this->obsRegion();
        $this->tracingReady($region);

        $config = app(TracingInstallService::class)->uninstall($region);

        $this->assertNull($config->status);
        $this->assertSame(['otel-collector', 'tempo'], $this->helm->uninstalls);
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/api/v1/namespaces/observability/secrets/otel-collector-export'));
    }
}
