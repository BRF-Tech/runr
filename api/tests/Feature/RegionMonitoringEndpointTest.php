<?php

namespace Tests\Feature;

use App\Jobs\InstallRedisJob;
use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\Helpers\MonitoringEndpointAccess;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KnativeInstallService;
use App\Services\KnativeLogService;
use App\Services\KnativeMetricsService;
use App\Services\KnativeNodeService;
use App\Services\KnativeStatusService;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Providers\TenancyServiceProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * An operator-supplied Prometheus/Loki is an in-cluster Service of the region cluster, read through
 * the region's K8s API service proxy. The central API never fetches an operator-supplied URL
 * itself (SSRF), and a direct call happens only when the region is RunR's own cluster — elsewhere
 * the in-cluster name would resolve inside RunR's cluster and silently read another Prometheus.
 */
class RegionMonitoringEndpointTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const SERVER = 'https://region.test:6443';

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
    }

    protected function tearDown(): void
    {
        MonitoringEndpointAccess::setResolver(null);
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /** Cluster API: version 1.31, namespaces don't exist yet, every write succeeds. */
    private function fakeClusterApi(array $extra = []): void
    {
        Http::fake(array_merge($extra, [
            self::SERVER.'/version' => Http::response(['major' => '1', 'minor' => '31']),
            self::SERVER.'/api/v1/namespaces/*' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Namespace']),
            self::SERVER.'/*' => Http::response(['kind' => 'Status']),
        ]));
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
        ], $attributes));
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'redis_mode' => 'none',
        ], $overrides);
    }

    // ── Validation / who may set it ──────────────────────────────────────────

    public function test_store_rejects_urls_that_are_not_an_in_cluster_service(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeClusterApi();
        $user = User::factory()->create(['username' => 'admin']);

        foreach ([
            'prometheus_url' => 'http://169.254.169.254:80/latest/meta-data',
            'loki_url' => 'https://loki.example.com:443',
        ] as $field => $url) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/knative-regions', $this->storePayload([$field => $url]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/knative-regions', $this->storePayload([
                'prometheus_url' => 'http://prom.monitoring.svc:9090/'.str_repeat('p', 240),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prometheus_url');

        $this->assertSame(0, KnativeRegion::count());
        Http::assertNotSent(fn (Request $r) => ! str_starts_with($r->url(), self::SERVER));
    }

    public function test_store_accepts_a_service_url_and_a_loki_tenant(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeClusterApi();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/knative-regions', $this->storePayload([
                'prometheus_url' => 'http://prometheus-operated.monitoring.svc.cluster.local:9090',
                'loki_url' => 'http://loki-gateway.monitoring.svc:80',
                'loki_org_id' => 'platform-tenant',
            ]))
            ->assertCreated();

        $region = KnativeRegion::where('name', 'fn')->first();
        $this->assertFalse($region->prometheus_managed);
        $this->assertFalse($region->loki_managed);
        $this->assertSame('platform-tenant', $region->loki_org_id);
    }

    public function test_monitoring_fields_are_refused_to_a_caller_who_may_not_set_them(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeClusterApi();
        $user = User::factory()->create(['username' => 'member']);
        MonitoringEndpointAccess::setResolver(fn () => false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/knative-regions', $this->storePayload(['prometheus_url' => 'http://prom.monitoring.svc:9090']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prometheus_url');

        // Without the fields the same caller creates the region
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/knative-regions', $this->storePayload())
            ->assertCreated();
    }

    public function test_with_tenancy_only_platform_admins_and_the_tenant_owner_may_set_them(): void
    {
        $this->setUpTenancy();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        $admin = User::factory()->create(['username' => 'root']);
        $admin->forceFill(['is_admin' => true])->save();
        $owner = User::factory()->create(['username' => 'owner']);
        $member = User::factory()->create(['username' => 'member']);
        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $member->id, 'role' => 'admin']);

        $request = function (User $user, ?Tenant $tenant) {
            $r = HttpRequest::create('/api/knative-regions', 'POST');
            $r->setUserResolver(fn () => $user);
            if ($tenant) {
                $r->attributes->set('tenant', $tenant);
            }

            return $r;
        };

        $this->assertTrue(TenancyServiceProvider::mayConfigureMonitoring($request($admin, null)));
        $this->assertTrue(TenancyServiceProvider::mayConfigureMonitoring($request($owner, $tenant)));
        $this->assertFalse(TenancyServiceProvider::mayConfigureMonitoring($request($member, $tenant)));
        // A non-admin outside a tenant would create a platform region
        $this->assertFalse(TenancyServiceProvider::mayConfigureMonitoring($request($owner, null)));
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    public function test_a_remote_region_is_read_through_its_api_service_proxy(): void
    {
        Http::fake(fn () => Http::response(['data' => ['result' => [['value' => [0, '0.5']]]]]));
        $region = $this->region(['prometheus_url' => 'http://prom.monitoring.svc:9090/prom', 'prometheus_managed' => false]);

        $raw = $this->runParallel($region, ['cpu' => 'up']);

        $this->assertSame(0.5, $raw['cpu']);
        Http::assertSent(fn (Request $r) => str_starts_with(
            $r->url(),
            self::SERVER.'/api/v1/namespaces/monitoring/services/http:prom:9090/proxy/prom/api/v1/query?',
        ));
        // Never the in-cluster name from the central API
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'prom.monitoring.svc'));
    }

    public function test_runrs_own_cluster_is_read_directly(): void
    {
        $this->app->instance(RunrClusterIdentity::class, new class extends RunrClusterIdentity
        {
            public function isRunrCluster(KnativeRegion $region): bool
            {
                return true;
            }
        });
        Http::fake(fn () => Http::response(['data' => ['result' => [['value' => [0, '2']]]]]));
        $region = $this->region(['prometheus_url' => 'http://prometheus-operated.observability:9090']);

        $raw = $this->runParallel($region, ['cpu' => 'up']);

        $this->assertSame(2.0, $raw['cpu']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://prometheus-operated.observability.svc:9090/api/v1/query?'));
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::SERVER));
    }

    public function test_a_legacy_nodeport_url_is_never_called_and_runrs_prometheus_is_used(): void
    {
        Http::fake(fn () => Http::response(['data' => ['result' => []]]));
        $region = $this->region(['prometheus_url' => 'http://10.0.0.5:30090']);

        $this->runParallel($region, ['cpu' => 'up']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/namespaces/observability/services/http:prometheus-operated:9090/proxy/api/v1/query'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '10.0.0.5'));
    }

    private function runParallel(KnativeRegion $region, array $queries): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);

        return (fn () => $this->runParallel($k8s, $region, $queries))->call(new KnativeMetricsService);
    }

    // ── Loki ─────────────────────────────────────────────────────────────────

    private function logFixture(KnativeRegion $region): array
    {
        $project = new Project(['name' => 'app', 'service_type' => 'caas']);
        $project->forceFill(['id' => '01PROJECT', 'slug' => 'app']);
        $project->setRelation('knativeRegion', $region);

        $deployment = new Deployment(['status' => 'active']);
        $deployment->setRelation('version', new Version(['number' => 1]));

        return [$project, $deployment];
    }

    /** Streams the fake Loki answers with (mutable between calls — Http::fake stubs stack). */
    private array $lokiStreams = [];

    private function fakeLokiAndPods(array $streams): void
    {
        $this->lokiStreams = $streams;

        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_contains($path, '/services/http:loki-gateway:80/proxy/loki/api/v1/query_range') => Http::response(['data' => ['result' => $this->lokiStreams]]),
                str_ends_with($path, '/namespaces/fn/pods') => Http::response(['items' => [[
                    'metadata' => ['name' => 'app-00001-deployment-abc'],
                    'spec' => ['containers' => [['name' => 'app']]],
                    'status' => ['phase' => 'Running'],
                ]]]),
                str_ends_with($path, '/pods/app-00001-deployment-abc/log') => Http::response('hello from kubectl'),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });
    }

    public function test_an_empty_loki_answer_falls_back_to_pod_logs(): void
    {
        $this->fakeLokiAndPods([]);
        $region = $this->region([
            'loki_url' => 'http://loki-gateway.monitoring.svc:80',
            'loki_managed' => false,
            'loki_status' => 'ready',
            'loki_org_id' => 'platform-tenant',
        ]);
        [$project, $deployment] = $this->logFixture($region);

        $result = app(KnativeLogService::class)->getDeploymentLogs($project, $deployment);

        $this->assertStringContainsString('hello from kubectl', $result['logs']);
        $this->assertArrayNotHasKey('source', $result);
        // The Loki query went through the region proxy with the configured tenant
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/services/http:loki-gateway:80/proxy/loki/api/v1/query_range')
            && $r->header('X-Scope-OrgID') === ['platform-tenant']);
    }

    public function test_loki_lines_and_empty_incremental_polls_stay_on_loki(): void
    {
        $this->fakeLokiAndPods([[
            'stream' => ['pod' => 'app-00001-deployment-abc', 'container' => 'app'],
            'values' => [['1700000000000000000', 'hello from loki']],
        ]]);
        $region = $this->region([
            'loki_url' => 'http://loki-gateway.monitoring.svc:80',
            'loki_managed' => false,
            'loki_status' => 'ready',
        ]);
        [$project, $deployment] = $this->logFixture($region);

        $first = app(KnativeLogService::class)->getDeploymentLogs($project, $deployment);
        $this->assertSame('loki', $first['source']);
        $this->assertStringContainsString('hello from loki', $first['logs']);
        Http::assertNotSent(fn (Request $r) => $r->hasHeader('X-Scope-OrgID'));

        // Incremental poll with nothing new: an empty Loki answer is the answer
        $this->lokiStreams = [];
        $next = app(KnativeLogService::class)->getDeploymentLogs($project, $deployment, 1700000000000);
        $this->assertSame('loki', $next['source']);
        $this->assertSame('', $next['logs']);
    }

    // ── Operator-managed Prometheus: RunR's scrape targets + rules ───────────

    private function applyScrapeResources(bool $crdsExist): bool
    {
        Http::fake(function (Request $r) use ($crdsExist) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_contains($path, '/customresourcedefinitions/')) {
                return $crdsExist ? Http::response(['metadata' => ['name' => basename($path)]]) : Http::response([], 404);
            }
            if ($r->method() === 'GET' && str_ends_with($path, '/nodes')) {
                return Http::response(['items' => []]);
            }

            return Http::response(['kind' => 'Status']);
        });

        $k8s = new KubernetesClient($this->kubeconfig());

        return (fn () => $this->applyScrapeResourcesForExternalPrometheus($k8s, 'knative-serving'))
            ->call(new KnativeInstallService);
    }

    public function test_an_operator_managed_prometheus_gets_runrs_podmonitors_and_rules(): void
    {
        $this->assertTrue($this->applyScrapeResources(true));

        $applied = function (string $suffix): ?Request {
            $pair = Http::recorded(fn (Request $r) => $r->method() === 'PATCH'
                && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), $suffix))->first();

            return $pair ? $pair[0] : null;
        };

        $this->assertNotNull($applied('/namespaces/observability/daemonsets/cadvisor'));
        $this->assertNotNull($applied('/namespaces/observability/podmonitors/cadvisor-runr'));
        $rule = $applied('/namespaces/observability/prometheusrules/runr-metrics');
        $this->assertSame('runr', json_decode($rule->body(), true)['metadata']['labels']['app.kubernetes.io/part-of']);

        $knative = json_decode($applied('/namespaces/observability/podmonitors/knative-serving-runr')->body(), true);
        $this->assertSame(['knative-serving'], $knative['spec']['namespaceSelector']['matchNames']);
        $this->assertSame('metrics', $knative['spec']['podMetricsEndpoints'][0]['port']);
        $this->assertSame([], $knative['spec']['selector']);
        $this->assertSame('runr', $knative['metadata']['labels']['app.kubernetes.io/part-of']);
    }

    public function test_without_prometheus_operator_crds_nothing_is_applied(): void
    {
        $this->assertFalse($this->applyScrapeResources(false));

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    // ── Setup wizard ─────────────────────────────────────────────────────────

    public function test_the_setup_wizard_passes_the_monitoring_endpoints_to_the_region(): void
    {
        Bus::fake([InstallRegistryAndCreateRegionJob::class, InstallRedisJob::class]);
        $this->fakeClusterApi();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/registry-setup/start', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'registry_mode' => 'external',
            'external_address' => 'registry.example.test',
            'redis_mode' => 'none',
            'prometheus_url' => 'http://prom.monitoring.svc:9090',
            'loki_url' => 'http://loki-gateway.monitoring.svc:80',
            'loki_org_id' => 'platform-tenant',
        ])->assertAccepted();

        $job = null;
        Bus::assertDispatched(InstallRegistryAndCreateRegionJob::class, function ($dispatched) use (&$job) {
            $job = $dispatched;

            return true;
        });

        $region = (fn () => $this->createRegion(app(KnativeStatusService::class), app(KnativeNodeService::class), 'registry.example.test', 'external'))
            ->call($job);

        $fresh = $region->fresh();
        $this->assertSame('http://prom.monitoring.svc:9090', $fresh->prometheus_url);
        $this->assertFalse($fresh->prometheus_managed);
        $this->assertSame('http://loki-gateway.monitoring.svc:80', $fresh->loki_url);
        $this->assertFalse($fresh->loki_managed);
        $this->assertSame('ready', $fresh->loki_status);
        $this->assertSame('platform-tenant', $fresh->loki_org_id);
    }

    public function test_the_setup_wizard_rejects_an_external_monitoring_url(): void
    {
        Bus::fake([InstallRegistryAndCreateRegionJob::class]);
        $this->fakeClusterApi();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/registry-setup/start', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'registry_mode' => 'external',
            'external_address' => 'registry.example.test',
            'prometheus_url' => 'http://metadata.google.internal:80',
        ])->assertUnprocessable()->assertJsonValidationErrors('prometheus_url');

        Bus::assertNotDispatched(InstallRegistryAndCreateRegionJob::class);
    }
}
