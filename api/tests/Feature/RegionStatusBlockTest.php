<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * The status block of an inactive or maintenance region (runr-region-block-<region>, Traefik v3
 * rule syntax) covers every host the region answers on: its function domain, the project custom
 * domains routed on it and the domains of its Knative Services (a tenant's own base domain). It
 * is per region — regions on one cluster share the Knative Serving namespace — and
 * region:reconcile applies it to regions created inactive or whose Serving came up later, and
 * removes it from active ones. A region created with is_active false is inactive.
 */
class RegionStatusBlockTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private const NS = '/apis/traefik.io/v1alpha1/namespaces/knative-serving';

    /** @var array<string, array> live objects: path => body */
    private array $live = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        RegionReconciler::clearRegionSources();

        $this->fakeCluster([
            'GET /apis/serving.knative.dev/v1/namespaces/eu-1/services' => ['items' => [
                ['metadata' => ['name' => 'shop'], 'status' => ['url' => 'http://shop.eu-1.example.test']],
                ['metadata' => ['name' => 'acme-api'], 'status' => ['url' => 'https://acme-api.eu-1.acme-cloud.test']],
                ['metadata' => ['name' => 'worker'], 'status' => ['url' => 'http://worker.eu-1.svc.cluster.local']],
            ]],
            'GET /apis/traefik.io/v1alpha1/namespaces/eu-1/ingressroutes' => ['items' => [
                ['metadata' => ['name' => 'runr-domain-abc', 'annotations' => ['runr.dev/domain' => 'shop.customer.test']]],
                ['metadata' => ['name' => 'runr-domain-abc-tls', 'annotations' => ['runr.dev/domain' => 'shop.customer.test']]],
                ['metadata' => ['name' => 'runr-domain-def', 'annotations' => ['runr.dev/domain' => 'www.shop.eu-1.example.test']]],
            ]],
            'GET '.self::NS.'/ingressroutes/*' => function ($request) {
                $path = parse_url($request->url(), PHP_URL_PATH);

                return $this->live[$path] ?? [['kind' => 'Status', 'code' => 404], 404];
            },
            'GET /apis/apps/v1/namespaces/knative-serving/deployments/runr-status-responder' => fn () => $this->responderLive
                ? ['metadata' => ['name' => 'runr-status-responder']]
                : [['kind' => 'Status', 'code' => 404], 404],
            'GET '.self::NS.'/middlewares/*' => function ($request) {
                $path = parse_url($request->url(), PHP_URL_PATH);

                return $this->live[$path] ?? [['kind' => 'Status', 'code' => 404], 404];
            },
        ]);
    }

    protected function tearDown(): void
    {
        RegionReconciler::clearRegionSources();
        parent::tearDown();
    }

    private function region(string $name = 'eu-1', array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => $name, 'project_namespace' => $name, 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_serving_status' => 'ready', 'knative_settings' => ['knative_domain' => "{$name}.example.test"],
        ], $attributes));
    }

    private function routePatch(string $name): ?array
    {
        $calls = $this->k8sCallsTo('PATCH', self::NS.'/ingressroutes/'.$name);

        return $calls ? end($calls)['body'] : null;
    }

    public function test_an_inactive_region_blocks_its_function_domain_custom_domains_and_knative_service_domains(): void
    {
        $region = $this->region();

        app(RegionStatusService::class)->changeStatus($region, 'inactive');

        $route = $this->routePatch('runr-region-block-eu-1');
        $this->assertNotNull($route, 'a block route of its own');
        $this->assertSame(
            'HostRegexp(`^.+\\.eu-1\\.acme-cloud\\.test$`) || HostRegexp(`^.+\\.eu-1\\.example\\.test$`) || Host(`shop.customer.test`)',
            $route['spec']['routes'][0]['match'],
        );
        $this->assertSame(999999, $route['spec']['routes'][0]['priority']);
        $this->assertSame(['web', 'websecure'], $route['spec']['entryPoints']);
        $middleware = $this->k8sCallsTo('PATCH', self::NS.'/middlewares/runr-region-block-eu-1');
        $this->assertSame('/inactive', $middleware[0]['body']['spec']['replacePath']['path']);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/apis/apps/v1/namespaces/knative-serving/deployments/runr-status-responder'));
    }

    public function test_maintenance_answers_503_and_active_removes_only_its_own_block(): void
    {
        $eu1 = $this->region();
        $eu2 = $this->region('eu-2');

        app(RegionStatusService::class)->changeStatus($eu1, 'maintenance');
        app(RegionStatusService::class)->changeStatus($eu2, 'inactive');
        $this->assertSame('/maintenance', $this->k8sCallsTo('PATCH', self::NS.'/middlewares/runr-region-block-eu-1')[0]['body']['spec']['replacePath']['path']);

        $this->live[self::NS.'/ingressroutes/runr-region-block-eu-1'] = ['metadata' => ['name' => 'runr-region-block-eu-1']];
        app(RegionStatusService::class)->changeStatus($eu1->fresh(), 'active');

        $this->assertNotEmpty($this->k8sCallsTo('DELETE', self::NS.'/ingressroutes/runr-region-block-eu-1'));
        $this->assertSame([], $this->k8sCallsTo('DELETE', self::NS.'/ingressroutes/runr-region-block-eu-2'), 'the other region stays blocked');
    }

    public function test_the_unscoped_block_route_of_earlier_releases_is_removed(): void
    {
        $this->live[self::NS.'/ingressroutes/runr-region-block'] = ['spec' => ['routes' => [['match' => 'HostRegexp(`{subdomain:.+}.eu-1.example.test`)']]]];

        app(RegionStatusService::class)->changeStatus($this->region(), 'inactive');

        $this->assertNotEmpty($this->k8sCallsTo('DELETE', self::NS.'/ingressroutes/runr-region-block'));
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', self::NS.'/middlewares/runr-region-block'));
    }

    public function test_reconcile_blocks_a_region_created_inactive_and_unblocks_an_active_one(): void
    {
        $created = $this->region('eu-1', ['is_active' => false]);
        $this->assertSame('inactive', $created->region_status, 'is_active false is an inactive region');
        $active = $this->region('eu-2');
        $this->live[self::NS.'/ingressroutes/runr-region-block-eu-2'] = ['metadata' => ['name' => 'runr-region-block-eu-2']];

        Artisan::call('region:reconcile', ['--dry-run' => true]);
        $this->assertStringContainsString('would apply the status block knative-serving/runr-region-block-eu-1', Artisan::output());
        $this->assertNull($this->routePatch('runr-region-block-eu-1'), 'dry run');

        Artisan::call('region:reconcile');
        $output = Artisan::output();

        $this->assertStringContainsString('[status-block] region eu-1: applied the status block', $output);
        $this->assertNotNull($this->routePatch('runr-region-block-eu-1'));
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', self::NS.'/ingressroutes/runr-region-block-eu-2'));
        $this->assertSame('active', $active->fresh()->region_status);
    }

    public function test_an_up_to_date_block_is_left_alone(): void
    {
        $region = $this->region('eu-1', ['is_active' => false]);
        $match = 'HostRegexp(`^.+\\.eu-1\\.acme-cloud\\.test$`) || HostRegexp(`^.+\\.eu-1\\.example\\.test$`) || Host(`shop.customer.test`)';
        $this->live[self::NS.'/ingressroutes/runr-region-block-eu-1'] = ['spec' => ['routes' => [['match' => $match]]]];
        $this->live[self::NS.'/middlewares/runr-region-block-eu-1'] = ['spec' => ['replacePath' => ['path' => '/inactive']]];
        $this->fakeResponder();

        $this->assertSame([], app(RegionStatusService::class)->syncBlock($region));
        $this->assertNull($this->routePatch('runr-region-block-eu-1'));
    }

    public function test_is_active_and_region_status_stay_in_step(): void
    {
        $region = $this->region('eu-1', ['is_active' => false]);
        $this->assertSame('inactive', $region->fresh()->region_status);

        $region->update(['region_status' => 'maintenance']);
        $region->update(['is_active' => false]);
        $this->assertSame('maintenance', $region->fresh()->region_status, 'an inactive flag keeps maintenance');

        $region->update(['is_active' => true]);
        $this->assertSame('active', $region->fresh()->region_status);
        $this->assertTrue($region->fresh()->is_active);
    }

    public function test_the_migration_fixes_rows_written_before(): void
    {
        $region = $this->region();
        DB::table('knative_regions')->where('id', $region->id)->update(['is_active' => false, 'region_status' => 'active']);

        (require database_path('migrations/2026_10_18_100001_sync_inactive_region_status.php'))->up();

        $this->assertSame('inactive', $region->fresh()->region_status);
    }

    private function fakeResponder(): void
    {
        $this->responderLive = true;
    }

    /** The status responder Deployment runs */
    private bool $responderLive = false;
}
