<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\KnativeInstallService;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\KnativeServingConfigStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Modules\Tenancy\Providers\TenancyServiceProvider;
use Modules\Tenancy\Services\TenantRegionReconcileSource;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * region:reconcile — upgrade-time reconcile of existing regions (Helm bootstrap job). Region-side
 * changes of 0.3.8/0.3.9 (KnativeServing spec.config.gc/deployment, removal of the
 * unauthenticated Prometheus NodePort) otherwise never reached regions installed before them.
 */
class RegionReconcileCommandTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const NODEPORT_URL = 'http://10.0.0.5:30090';

    private const CR_PATH = '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving';

    /** @var array<string, array{uid: string, reachable: bool, config: ?array, nodeport: bool, agent: ?array}> */
    private array $clusters = [];

    /** @var list<array{method: string, host: string, path: string, body: string, type: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Not in a pod: RunR's own cluster is never detected (agent URL resolution, cluster identity)
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        // the tracing step installs no backend on these regions (TracingBackendReconcileStepTest)
        config(['observability.tracing.install_with_serving' => false]);
        RegionReconciler::clearRegionSources();
    }

    protected function tearDown(): void
    {
        RegionReconciler::clearRegionSources();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    // ── Fake clusters ────────────────────────────────────────────────────────

    private function cluster(string $host, array $state = []): string
    {
        $this->clusters[$host] = array_merge([
            'uid' => "uid-{$host}",
            'reachable' => true,
            'config' => ['domain' => ['fn.example.test' => '']],   // KnativeServing spec.config, null = no CR
            'nodeport' => false,
            'agent' => null,                                        // runr-agent env, null = none
        ], $state);

        return $host;
    }

    private function kubeconfig(string $host): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$host}:6443", 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function fakeClusters(): void
    {
        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $this->calls[] = [
                'method' => $method,
                'host' => $host,
                'path' => $path,
                'body' => $request->body(),
                'type' => $request->header('Content-Type')[0] ?? '',
            ];

            $c = $this->clusters[$host] ?? null;
            if (! $c || ! $c['reachable']) {
                return Http::response('', 503);
            }

            return match (true) {
                $path === '/version' => Http::response(['major' => '1', 'minor' => '31']),
                $path === '/api/v1/namespaces/kube-system' => Http::response(['metadata' => ['uid' => $c['uid']]]),
                $path === self::CR_PATH && $method === 'GET' => $c['config'] === null
                        ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                        : Http::response(['spec' => ['version' => '1.18.0', 'config' => $c['config']]]),
                $path === '/api/v1/namespaces/observability/services/prometheus-nodeport' && $method === 'GET' => $c['nodeport']
                        ? Http::response(['metadata' => ['name' => 'prometheus-nodeport']])
                        : Http::response(['kind' => 'Status', 'code' => 404], 404),
                $path === '/apis/apps/v1/namespaces/observability/deployments/runr-agent' && $method === 'GET' => $c['agent'] === null
                        ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                        : Http::response(['spec' => ['template' => ['spec' => ['containers' => [['env' => $c['agent']]]]]]]),
                in_array($method, ['PATCH', 'DELETE', 'POST', 'PUT'], true) => Http::response(['kind' => 'Status'], 200),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });
    }

    private function region(string $name, string $host, array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => $name,
            'project_namespace' => $name,
            'kubeconfig' => $this->kubeconfig($host),
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ], $attributes));
    }

    /** @return list<array{method: string, host: string, path: string, body: string, type: string}> */
    private function writes(?string $host = null): array
    {
        return array_values(array_filter(
            $this->calls,
            fn ($c) => $c['method'] !== 'GET' && ($host === null || $c['host'] === $host),
        ));
    }

    private function crPatches(?string $host = null): array
    {
        return array_values(array_filter($this->writes($host), fn ($c) => $c['path'] === self::CR_PATH));
    }

    private function reconcile(array $options = []): string
    {
        Artisan::call('region:reconcile', $options);

        return Artisan::output();
    }

    // ── Region selection ─────────────────────────────────────────────────────

    public function test_selects_active_ready_regions_and_patches_each_cluster_once(): void
    {
        $this->cluster('c1.test');
        $this->cluster('c2.test');
        $this->fakeClusters();

        // Two regions on c1 (same kube-system UID, e.g. a sibling region) → one patch
        $this->region('fn-a', 'c1.test');
        $this->region('fn-b', 'c1.test');
        $this->region('fn-c', 'c2.test');
        // Not reconciled: inactive, Serving not ready, demo
        $this->region('off', 'c2.test', ['is_active' => false]);
        $this->region('fresh', 'c2.test', ['knative_serving_status' => 'installing']);
        $this->region('demo', 'c2.test', ['is_demo' => true, 'kubeconfig' => 'demo']);

        $output = $this->reconcile();

        $this->assertCount(1, $this->crPatches('c1.test'));
        $this->assertCount(1, $this->crPatches('c2.test'));
        $this->assertStringContainsString('fn-a', $output);
        $this->assertStringContainsString('fn-b', $output);
        $this->assertStringContainsString('fn-c', $output);
        $this->assertStringNotContainsString('off (', $output);
        $this->assertStringNotContainsString('fresh (', $output);
        $this->assertStringNotContainsString('demo (', $output);
    }

    public function test_region_option_limits_the_run_to_one_region(): void
    {
        $this->cluster('c1.test');
        $this->cluster('c2.test');
        $this->fakeClusters();

        $this->region('fn-a', 'c1.test');
        $only = $this->region('fn-c', 'c2.test');

        $this->reconcile(['--region' => $only->id]);

        $this->assertCount(0, $this->crPatches('c1.test'));
        $this->assertCount(1, $this->crPatches('c2.test'));
    }

    public function test_tenant_owned_regions_are_reconciled_and_synced_copies_are_not(): void
    {
        $this->setUpTenancy();
        TenantRegionReconcileSource::register();

        $this->cluster('central.test');
        $this->cluster('tenant.test', ['nodeport' => true]);
        $this->cluster('copy.test');
        $this->fakeClusters();

        $this->region('platform', 'central.test');

        $tenant = $this->createTenantWithSchema('acme');
        $ownId = $tenant->run(function () {
            $own = $this->region('own', 'tenant.test', ['prometheus_url' => self::NODEPORT_URL]);

            // A synced copy of a platform region (is_synced = true): reconciled through its central row
            $copy = new KnativeRegion([
                'name' => 'copy',
                'kubeconfig' => $this->kubeconfig('copy.test'),
                'is_active' => true,
                'knative_serving_status' => 'ready',
            ]);
            $copy->forceFill(['is_synced' => true])->save();

            return $own->id;
        });

        $output = $this->reconcile();

        $this->assertStringContainsString('own ('.$ownId.', tenant acme)', $output);
        $this->assertStringNotContainsString('copy (', $output);
        $this->assertCount(1, $this->crPatches('central.test'));
        $this->assertCount(1, $this->crPatches('tenant.test'));
        $this->assertSame([], $this->writes('copy.test'));

        // The tenant-owned row is written in the tenant's own schema
        $this->assertTrue(collect($this->writes('tenant.test'))->contains(
            fn ($c) => $c['method'] === 'DELETE' && $c['path'] === '/api/v1/namespaces/observability/services/prometheus-nodeport'
        ));
        $url = $tenant->run(fn () => KnativeRegion::find($ownId)->prometheus_url);
        $this->assertSame(KnativeInstallService::prometheusClusterIpUrl('observability'), $url);
    }

    public function test_the_status_block_pass_covers_inactive_tenant_owned_regions_in_their_context(): void
    {
        $this->setUpTenancy();
        TenantRegionReconcileSource::register();

        $this->cluster('tenant.test');
        $this->cluster('copy.test');
        $this->fakeClusters();

        $tenant = $this->createTenantWithSchema('acme');
        $tenant->run(function () {
            $own = $this->region('own', 'tenant.test', ['is_active' => false, 'knative_settings' => ['knative_domain' => 'own.acme.test']]);
            $this->assertSame('inactive', $own->region_status);

            // A synced copy of a platform region: the central row's pass blocks it
            $copy = new KnativeRegion([
                'name' => 'copy', 'kubeconfig' => $this->kubeconfig('copy.test'), 'is_active' => false,
                'knative_serving_status' => 'ready', 'knative_settings' => ['knative_domain' => 'copy.example.test'],
            ]);
            $copy->forceFill(['is_synced' => true])->save();
        });

        $route = '/apis/traefik.io/v1alpha1/namespaces/knative-serving/ingressroutes/runr-region-block-own';

        $output = $this->reconcile(['--dry-run' => true]);
        $this->assertStringContainsString('[status-block] region own (tenant acme): would apply the status block knative-serving/runr-region-block-own', $output);
        $this->assertSame([], $this->writes('tenant.test'), 'dry run');

        $output = $this->reconcile();
        $this->assertStringContainsString('No regions to reconcile.', $output, 'an inactive region gets no cluster steps');
        $this->assertStringContainsString('[status-block] region own (tenant acme): applied the status block', $output);
        $this->assertTrue(collect($this->writes('tenant.test'))->contains(fn ($c) => $c['method'] === 'PATCH' && $c['path'] === $route));
        $this->assertStringNotContainsString('region copy', $output);
        $this->assertSame([], $this->writes('copy.test'));
    }

    public function test_the_tenant_migration_gives_inactive_rows_their_status(): void
    {
        $this->setUpTenancy();
        $tenant = $this->createTenantWithSchema('acme');

        $status = $tenant->run(function () {
            $region = $this->region('own', 'tenant.test');
            DB::table('knative_regions')->where('id', $region->id)->update(['is_active' => false, 'region_status' => 'active']);
            $active = $this->region('live', 'tenant.test');

            (require base_path('Modules/Tenancy/Database/TenantMigrations/0057_sync_inactive_region_status.php'))->up();
            (require base_path('Modules/Tenancy/Database/TenantMigrations/0057_sync_inactive_region_status.php'))->up();

            return DB::table('knative_regions')->orderBy('name')->pluck('region_status', 'name')->all();
        });

        $this->assertSame(['live' => 'active', 'own' => 'inactive'], $status);
    }

    // ── KnativeServing CR ────────────────────────────────────────────────────

    public function test_knative_serving_cr_gets_a_merge_patch_of_gc_and_deployment_only(): void
    {
        $this->cluster('c1.test', ['config' => [
            'domain' => ['fn.example.test' => ''],
            'network' => ['ingress-class' => 'traefik.ingress.networking.knative.dev'],
        ]]);
        $this->fakeClusters();
        $this->region('fn', 'c1.test');

        $output = $this->reconcile();

        $patches = $this->crPatches();
        $this->assertCount(1, $patches);
        $this->assertSame('PATCH', $patches[0]['method']);
        $this->assertStringStartsWith('application/merge-patch+json', $patches[0]['type']);
        $this->assertSame([
            'spec' => ['config' => [
                'gc' => KnativeInstallService::configGcValues(),
                'deployment' => KnativeInstallService::configDeploymentValues(),
            ]],
        ], json_decode($patches[0]['body'], true));

        // Never a server-side apply of a rebuilt CR (would drop spec.version / spec.config.domain)
        $this->assertFalse(collect($this->writes())->contains(fn ($c) => str_starts_with($c['type'], 'application/apply-patch')));
        $this->assertStringContainsString("roll each Revision's pods once", $output);
    }

    public function test_an_up_to_date_cr_is_not_patched(): void
    {
        $this->cluster('c1.test', ['config' => [
            'gc' => KnativeInstallService::configGcValues(),
            'deployment' => KnativeInstallService::configDeploymentValues(),
        ]]);
        $this->fakeClusters();
        $this->region('fn', 'c1.test');

        $this->reconcile();

        $this->assertSame([], $this->writes());
        // Only the outdated section is sent when one differs
        $this->assertSame(['gc'], array_keys(KnativeServingConfigStep::outdatedSections([
            'gc' => ['min-non-active-revisions' => '1'] + KnativeInstallService::configGcValues(),
            'deployment' => KnativeInstallService::configDeploymentValues(),
        ])));
    }

    // ── Prometheus exposure ──────────────────────────────────────────────────

    public function test_nodeport_is_removed_and_url_rewritten_only_for_a_managed_prometheus(): void
    {
        $this->cluster('managed.test', ['nodeport' => true, 'agent' => [
            ['name' => 'RUNR_REGION_ID', 'value' => 'placeholder'],
            ['name' => 'PROMETHEUS_URL', 'value' => self::NODEPORT_URL],
        ]]);
        $this->cluster('external.test', ['nodeport' => true]);
        $this->cluster('current.test');
        $this->fakeClusters();

        $managed = $this->region('managed', 'managed.test', ['prometheus_url' => self::NODEPORT_URL]);
        $external = $this->region('external', 'external.test', [
            'prometheus_url' => 'http://prom.monitoring.svc:30090',
            'prometheus_managed' => false,
        ]);
        $current = $this->region('current', 'current.test', ['prometheus_url' => 'http://prometheus-operated.observability:9090']);

        // The cluster's agent belongs to the managed region and still reads the NodePort URL
        $this->clusters['managed.test']['agent'][0]['value'] = $managed->id;

        $this->reconcile();

        $inCluster = KnativeInstallService::prometheusClusterIpUrl('observability');
        $this->assertSame($inCluster, $managed->fresh()->prometheus_url);
        $this->assertSame('http://prom.monitoring.svc:30090', $external->fresh()->prometheus_url);
        $this->assertSame('http://prometheus-operated.observability:9090', $current->fresh()->prometheus_url);

        $deletes = fn (string $host) => collect($this->writes($host))->filter(fn ($c) => $c['method'] === 'DELETE')->pluck('path')->all();
        $this->assertSame(['/api/v1/namespaces/observability/services/prometheus-nodeport'], $deletes('managed.test'));
        $this->assertSame([], $deletes('external.test'));

        // Agent redeployed with the in-cluster URL; no agent touched elsewhere
        $agentPatch = collect($this->writes('managed.test'))
            ->first(fn ($c) => $c['path'] === '/apis/apps/v1/namespaces/observability/deployments/runr-agent');
        $this->assertNotNull($agentPatch, 'runr-agent was not redeployed');
        $env = collect(json_decode($agentPatch['body'], true)['spec']['template']['spec']['containers'][0]['env']);
        $this->assertSame($inCluster, $env->firstWhere('name', 'PROMETHEUS_URL')['value']);
        $this->assertFalse(collect($this->writes('external.test'))->contains(fn ($c) => str_contains($c['path'], 'runr-agent')));
    }

    public function test_platform_region_url_rewrite_reaches_the_tenant_copies(): void
    {
        $this->setUpTenancy();
        // What TenancyServiceProvider wires with TENANCY_ENABLED: central → tenant region sync and
        // the tenant-aware region model
        (fn () => $this->registerSyncEvents())->call(new TenancyServiceProvider($this->app));
        $this->app->bind(KnativeRegion::class, TenantAwareKnativeRegion::class);

        $this->cluster('c1.test', ['nodeport' => true]);
        $this->fakeClusters();

        $region = $this->region('platform', 'c1.test', ['prometheus_url' => self::NODEPORT_URL]);
        $tenant = $this->createTenantWithSchema('acme');
        $tenant->regions()->attach($region->id, ['cpu_quota_m' => 1000, 'memory_quota_mb' => 1024]);
        CentralSyncableRegion::find($region->id)->triggerSyncEvent();
        $this->assertSame(self::NODEPORT_URL, $tenant->run(fn () => KnativeRegion::find($region->id)?->prometheus_url));

        $this->reconcile();

        $inCluster = KnativeInstallService::prometheusClusterIpUrl('observability');
        $this->assertSame($inCluster, $region->fresh()->prometheus_url);
        $this->assertSame($inCluster, $tenant->run(fn () => KnativeRegion::find($region->id)->prometheus_url));
    }

    // ── Dry run / failures ───────────────────────────────────────────────────

    public function test_dry_run_changes_nothing(): void
    {
        $this->cluster('c1.test', ['nodeport' => true]);
        $this->fakeClusters();
        $region = $this->region('fn', 'c1.test', ['prometheus_url' => self::NODEPORT_URL]);

        $output = $this->reconcile(['--dry-run' => true]);

        $this->assertSame([], $this->writes());
        $this->assertSame(self::NODEPORT_URL, $region->fresh()->prometheus_url);
        $this->assertStringContainsString('would merge-patch', $output);
        $this->assertStringContainsString('would delete', $output);
        $this->assertStringContainsString('would set prometheus_url', $output);
    }

    public function test_an_unreachable_region_does_not_stop_the_others(): void
    {
        $this->cluster('down.test', ['reachable' => false]);
        $this->cluster('up.test');
        $this->fakeClusters();

        $this->region('down', 'down.test');
        $this->region('up', 'up.test');

        $exit = Artisan::call('region:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unreachable', $output);
        $this->assertCount(1, $this->crPatches('up.test'));
    }
}
