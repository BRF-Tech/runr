<?php

namespace Tests\Feature;

use App\Jobs\ConfigureRegionMonitoringJob;
use App\Jobs\InstallRedisJob;
use App\Jobs\InstallRegionRegistryJob;
use App\Models\KnativeRegion;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\KnativeInstallService;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * What a region could only get at its creation can be changed later: its registry (set up after
 * the region was created, or after DELETE …/registry; POST /knative-regions with registry fields
 * sets it up too — it was stored and never installed), its Prometheus and Loki
 * (PUT …/monitoring) and its function TLS, which can be switched off (DELETE …/function-tls).
 */
class RegionChangePathsTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private const STORE = '/apis/traefik.io/v1alpha1/namespaces/knative-serving/tlsstores/default';

    private const SECRET = '/api/v1/namespaces/knative-serving/secrets/runr-function-tls';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signedIn($this->userWithRole('owner'));
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_operator_status' => 'ready', 'knative_serving_status' => 'ready',
        ], $attributes));
    }

    public function test_an_external_registry_is_set_up_for_an_existing_region(): void
    {
        $region = $this->region();
        Bus::fake([InstallRegionRegistryJob::class]);

        $this->postJson("/api/knative-regions/{$region->id}/registry", [
            'registry_mode' => 'external', 'external_address' => 'registry.example.test/team',
            'external_username' => 'ci', 'external_password' => 'secret',
        ])->assertStatus(202)->assertJsonPath('region.registry_status', 'installing');

        Bus::assertDispatched(InstallRegionRegistryJob::class);
        $this->postJson("/api/knative-regions/{$region->id}/registry", ['registry_mode' => 'external', 'external_address' => 'x.test'])
            ->assertStatus(409);
    }

    public function test_the_registry_job_tests_and_stores_the_registry(): void
    {
        $region = $this->region(['registry_status' => 'installing']);
        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('testExternalRegistry')->once()->andReturn(['success' => true, 'message' => 'ok']);
        $this->app->instance(ZotRegistryService::class, $zot);

        dispatch_sync(new InstallRegionRegistryJob($region, [
            'registry_mode' => 'external', 'external_address' => 'registry.example.test/team',
            'external_username' => 'ci', 'external_password' => 'secret',
        ]));

        $region->refresh();
        $this->assertSame(['external', 'registry.example.test/team', 'ready', 'ci'], [$region->registry_mode, $region->registry_address, $region->registry_status, $region->registry_username]);
        $this->assertSame('secret', $region->registry_password);
        $this->assertTrue($region->has_registry);
    }

    public function test_a_failed_registry_test_is_reported_on_the_region(): void
    {
        $region = $this->region(['registry_status' => 'installing']);
        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('detectExisting')->andReturnNull();
        $zot->shouldReceive('testPvcBound')->andReturn(['success' => false, 'message' => 'PVC did not bind within 60 s']);
        $zot->shouldNotReceive('install');
        $this->app->instance(ZotRegistryService::class, $zot);

        dispatch_sync(new InstallRegionRegistryJob($region, [
            'registry_mode' => 'internal', 'storage_type' => 'pvc', 'pvc_config' => ['storageClass' => 'slow', 'size' => '10Gi'],
        ]));

        $this->assertSame(['error', 'PVC did not bind within 60 s'], [$region->fresh()->registry_status, $region->fresh()->registry_status_message]);
    }

    public function test_an_internal_registry_is_removed_before_another_is_set_up(): void
    {
        $region = $this->region(['registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => '10.0.0.5:5000']);

        $this->postJson("/api/knative-regions/{$region->id}/registry", ['registry_mode' => 'external', 'external_address' => 'registry.example.test'])
            ->assertStatus(409);
    }

    public function test_a_region_created_with_registry_fields_gets_its_registry_set_up(): void
    {
        Bus::fake([InstallRegionRegistryJob::class, InstallRedisJob::class]);
        $this->fakeCluster(['GET /version' => ['major' => '1', 'minor' => '31']]);

        $this->postJson('/api/knative-regions', [
            'name' => 'eu-2', 'kubeconfig' => $this->fakeKubeconfig(), 'nodes' => [],
            'registry_mode' => 'internal', 'registry_storage_type' => 'pvc',
            'registry_storage_config' => ['storageClass' => 'fast', 'size' => '20Gi'],
        ])->assertCreated()->assertJsonPath('registry_status', 'installing');

        Bus::assertDispatched(InstallRegionRegistryJob::class, function (InstallRegionRegistryJob $job) {
            $config = (fn () => $this->config)->call($job);

            return $config['registry_mode'] === 'internal' && $config['pvc_config'] === ['storageClass' => 'fast', 'size' => '20Gi'];
        });
    }

    public function test_function_tls_can_be_switched_off(): void
    {
        $region = $this->region(['function_tls_mode' => 'custom', 'function_tls_secret_name' => 'runr-function-tls', 'function_tls_expires_at' => now()->addYear()]);
        $this->fakeCluster([
            'GET '.self::STORE => ['metadata' => ['name' => 'default', 'labels' => ['app.kubernetes.io/managed-by' => 'runr']]],
            'GET '.self::SECRET => ['metadata' => ['name' => 'runr-function-tls', 'labels' => ['app.kubernetes.io/managed-by' => 'runr']]],
        ]);

        $this->deleteJson("/api/knative-regions/{$region->id}/function-tls")->assertOk()->assertJsonPath('region.function_tls_mode', null);

        $this->assertNotEmpty($this->k8sCallsTo('DELETE', self::STORE));
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', self::SECRET));
        $this->assertNull($region->fresh()->function_tls_expires_at);
    }

    public function test_a_tlsstore_runr_did_not_create_stays(): void
    {
        $region = $this->region(['function_tls_mode' => 'console']);
        $this->fakeCluster([
            'GET '.self::STORE => ['metadata' => ['name' => 'default', 'labels' => []]],
        ]);

        $this->deleteJson("/api/knative-regions/{$region->id}/function-tls")->assertOk();

        $this->assertSame([], $this->k8sCallsTo('DELETE', self::STORE));
        $this->assertNull($region->fresh()->function_tls_mode);
    }

    public function test_monitoring_switches_to_an_existing_instance_and_back(): void
    {
        $region = $this->region(['prometheus_url' => 'http://prometheus-operated.observability:9090']);
        Bus::fake([ConfigureRegionMonitoringJob::class]);

        $this->putJson("/api/knative-regions/{$region->id}/monitoring", [
            'prometheus_url' => 'http://kps-prometheus.monitoring.svc:9090', 'loki_url' => 'http://loki.logging.svc:3100', 'loki_org_id' => 'runr',
        ])->assertOk()
            ->assertJsonPath('region.monitoring.prometheus_managed', false)
            ->assertJsonPath('region.monitoring.loki_url', 'http://loki.logging.svc:3100');

        $region->refresh();
        $this->assertFalse($region->isPrometheusManaged());
        $this->assertFalse($region->isLokiManaged());
        $this->assertSame(['http://kps-prometheus.monitoring.svc:9090', 'http://loki.logging.svc:3100', 'runr', 'ready'], [$region->prometheus_url, $region->loki_url, $region->loki_org_id, $region->loki_status]);
        Bus::assertDispatched(ConfigureRegionMonitoringJob::class, fn ($job) => $job->managed === [] && $job->externalPrometheus);

        $this->putJson("/api/knative-regions/{$region->id}/monitoring", ['prometheus_url' => null])->assertOk();
        $this->assertTrue($region->fresh()->isPrometheusManaged());
        $this->assertFalse($region->fresh()->isLokiManaged(), 'left out: kept');
        Bus::assertDispatched(ConfigureRegionMonitoringJob::class, fn ($job) => $job->managed === ['prometheus'] && ! $job->externalPrometheus);

        $this->putJson("/api/knative-regions/{$region->id}/monitoring", ['prometheus_url' => 'http://prometheus.monitoring.example.com:9090'])
            ->assertStatus(422)->assertJsonValidationErrors('prometheus_url');
    }

    public function test_a_region_without_serving_installs_nothing_now(): void
    {
        $region = $this->region(['knative_serving_status' => 'not_installed', 'prometheus_managed' => false, 'prometheus_url' => 'http://p.monitoring.svc:9090']);
        Bus::fake([ConfigureRegionMonitoringJob::class]);

        $this->putJson("/api/knative-regions/{$region->id}/monitoring", ['prometheus_url' => null])->assertOk();

        $this->assertTrue($region->fresh()->isPrometheusManaged(), 'the Serving install will install it');
        Bus::assertNotDispatched(ConfigureRegionMonitoringJob::class);
    }

    public function test_switching_back_installs_runrs_prometheus(): void
    {
        $installs = [];
        $this->app->instance(HelmService::class, new class($installs) extends HelmService
        {
            public function __construct(private array &$installs) {}

            public function install(string $release, string $chart, string $namespace, array $setValues, string $kubeconfigYaml, ?string $version = null, array $valuesArray = [], array $setJsonValues = [], bool $wait = true): HelmResult
            {
                $this->installs[] = "{$release} {$chart} {$version}";

                return new HelmResult(true, '', '', 0);
            }

            public function repoAdd(string $name, string $url): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }

            public function repoUpdate(): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }
        });
        $this->fakeCluster();
        $region = $this->region(['prometheus_managed' => true, 'prometheus_url' => null]);

        app(KnativeInstallService::class)->applyMonitoring($region, ['prometheus'], false);

        $this->assertSame(['knative prometheus-community/kube-prometheus-stack 92.10.1'], $installs);
        $this->assertNotNull($region->fresh()->prometheus_url);
    }
}
