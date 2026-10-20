<?php

namespace Tests\Feature;

use App\Jobs\CheckKnativeStatusJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\DomainMigrateService;
use App\Services\KnativeDeployService;
use App\Services\KnativeInstallService;
use App\Services\KnativeRedisService;
use App\Services\KnativeStatusService;
use App\Services\RegionMigrateService;
use App\Services\RegionStatusService;
use App\Services\SandboxInstallService;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Jobs\DetachRegionCleanupJob;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Modules\Tenancy\Providers\TenancyServiceProvider;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Platform regions reach a tenant as synced copies in its schema (central → tenant, one way):
 *  - the region status (active / inactive / maintenance) and the migration state are synced, the
 *    old kubeconfig and the pre-move state of a cluster migration are not; the registry address a
 *    move sets and its rollback restores reaches the copies (a tenant's own region keeps its
 *    pre-move state in the tenant's schema)
 *  - ending an assignment removes the copy once the tenant's projects there are torn down (and
 *    its gateways removed from the region); deleting a platform region removes every copy —
 *    projects on it stay without a region, like central ones
 *  - a region created inside a tenant is the tenant's own: owner_tenant_id, not synced; the
 *    upgrade migration sets the owner on those created before
 */
class TenantRegionSyncTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    /** @var list<string> "METHOD host/path" */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        // What TenancyServiceProvider wires with TENANCY_ENABLED: the region sync and ownership
        // events, the kubeconfig resolver of copies, the tenant-aware region model of the routes
        (fn () => $this->registerSyncEvents())->call(new TenancyServiceProvider($this->app));
        $this->app->bind(KnativeRegion::class, TenantAwareKnativeRegion::class);

        Http::fake(function (HttpRequest $request) {
            $this->calls[] = $request->method().' '.parse_url($request->url(), PHP_URL_HOST).parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['kind' => 'Status', 'items' => []], 200);
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /**
     * Region tables + settings (detach removes the tenant's domain) + the region_id cascades.
     *
     * @param  list<string>  $more  further tenant migrations
     */
    private function tenantSchema(string $slug, array $more = []): Tenant
    {
        return $this->createTenantWithSchema($slug, array_merge(self::TENANT_REGION_MIGRATIONS, [
            'Modules/Tenancy/Database/TenantMigrations/0004_create_settings_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0013_create_metric_snapshots_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0014_create_deployment_sessions_table.php',
        ], $more));
    }

    /**
     * RegionMigrateService for a move to a cluster where Knative already runs and the region's Zot
     * serves at $newRegistry; Redis and the sandbox runtimes have nothing to install.
     */
    private function migrateService(string $newRegistry): RegionMigrateService
    {
        $installer = Mockery::mock(KnativeInstallService::class);
        $installer->shouldReceive('runningKnative')->andReturn(['operator' => true, 'serving' => true]);
        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('detectExisting')->andReturn(null);
        $zot->shouldReceive('install')->andReturn(['address' => $newRegistry]);

        return new RegionMigrateService(
            $installer,
            app(KnativeDeployService::class),
            $zot,
            Mockery::mock(KnativeRedisService::class)->shouldIgnoreMissing(),
            Mockery::mock(SandboxInstallService::class)->shouldIgnoreMissing(),
        );
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

    private function platformRegion(string $name = 'platform'): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => $name, 'project_namespace' => $name, 'kubeconfig' => $this->kubeconfig("{$name}.test"),
            'is_active' => true, 'total_cpu_m' => 8000, 'total_memory_mb' => 16384,
        ]);
    }

    private function assign(Tenant $tenant, KnativeRegion $region): void
    {
        $tenant->regions()->attach($region->id, ['cpu_quota_m' => 1000, 'memory_quota_mb' => 1024]);
        CentralSyncableRegion::findOrFail($region->id)->triggerSyncEvent();
    }

    /** The tenant's row of the region (copy or own region), as stored. */
    private function rowIn(Tenant $tenant, string $regionId): ?object
    {
        return $tenant->run(fn () => DB::table('knative_regions')->where('id', $regionId)->first());
    }

    /** A project of the tenant on the region, with a gateway route to it; returns the project id. */
    private function projectWithGateway(Tenant $tenant, KnativeRegion $region): array
    {
        return $tenant->run(function () use ($region) {
            $project = Project::create(['name' => 'svc', 'service_type' => 'caas']);
            $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

            $gatewayId = (string) Str::ulid();
            DB::table('gateways')->insert(['id' => $gatewayId, 'user_id' => 1, 'knative_region_id' => $region->id, 'subdomain' => 'api', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('gateway_routes')->insert(['id' => (string) Str::ulid(), 'gateway_id' => $gatewayId, 'project_id' => $project->id, 'path' => '/svc', 'created_at' => now(), 'updated_at' => now()]);

            return [$project->id, $gatewayId];
        });
    }

    private function projectRegion(Tenant $tenant, string $projectId): ?string
    {
        return $tenant->run(fn () => DB::table('projects')->where('id', $projectId)->value('knative_region_id'));
    }

    public function test_the_region_status_and_migration_state_reach_the_tenant_copies(): void
    {
        $region = $this->platformRegion();
        $acme = $this->tenantSchema('acme');
        $this->assign($acme, $region);
        $this->assertSame('active', $this->rowIn($acme, $region->id)->region_status);

        // The region API changes the status on the route-bound (tenant-aware) model
        $bound = TenantAwareKnativeRegion::findOrFail($region->id);
        app(RegionStatusService::class)->changeStatus($bound, 'maintenance');

        $copy = $this->rowIn($acme, $region->id);
        $this->assertSame('maintenance', $copy->region_status);
        $this->assertFalse((bool) $copy->is_active);
        $this->assertTrue($acme->run(fn () => KnativeRegion::findOrFail($region->id)->is_maintenance), 'tenant deploys read the status from the copy');

        $bound->update(['migration_status' => 'dns_check', 'migration_old_ip' => '10.0.0.1', 'migration_old_kubeconfig' => 'old-credential']);

        $copy = $this->rowIn($acme, $region->id);
        $this->assertSame('dns_check', $copy->migration_status);
        $this->assertSame('10.0.0.1', $copy->migration_old_ip);
        $this->assertNull($copy->migration_old_kubeconfig, 'the old kubeconfig stays central');
    }

    public function test_a_migration_rollback_gives_the_copies_the_old_registry_address_back(): void
    {
        $region = $this->platformRegion();
        $region->update(['registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => '10.96.1.1:5000', 'registry_namespace' => 'runr-registry']);
        $acme = $this->tenantSchema('acme');
        $ops = $this->tenantSchema('ops');
        $this->assign($acme, $region);
        $this->assign($ops, $region);
        $service = $this->migrateService('10.96.2.2:5000');

        // MigrateRegionJob carries the route-bound model of the region API
        $service->migrate(TenantAwareKnativeRegion::findOrFail($region->id), $this->kubeconfig('new.test'));

        foreach ([$acme, $ops] as $tenant) {
            $copy = $this->rowIn($tenant, $region->id);
            $this->assertSame('10.96.2.2:5000', $copy->registry_address, "the move reached the copy in {$tenant->slug}");
            $this->assertNull($copy->migration_old_state, 'the pre-move state stays central');
        }

        $service->rollbackMigration(TenantAwareKnativeRegion::findOrFail($region->id));

        $this->assertSame('10.96.1.1:5000', $region->fresh()->registry_address);
        foreach ([$acme, $ops] as $tenant) {
            $copy = $this->rowIn($tenant, $region->id);
            $this->assertSame('10.96.1.1:5000', $copy->registry_address, "tenant deploys and builds in {$tenant->slug} read the old cluster's registry again");
            $this->assertNull($copy->migration_status);
        }
    }

    public function test_a_tenants_own_region_keeps_its_pre_move_state_in_the_tenant_schema(): void
    {
        config(['tenancy.enabled' => true]);
        $acme = $this->tenantSchema('acme', [
            'Modules/Tenancy/Database/TenantMigrations/0034_create_private_db_bindings_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0035_create_bindings_tables.php',
        ]);
        $own = $acme->run(fn () => KnativeRegion::create([
            'name' => 'own', 'project_namespace' => 'own', 'is_active' => true, 'kubeconfig' => $this->kubeconfig('own.test'),
            'registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => '10.96.1.1:5000', 'registry_namespace' => 'runr-registry',
        ]));
        $service = $this->migrateService('10.96.2.2:5000');

        $acme->run(fn () => $service->migrate(KnativeRegion::findOrFail($own->id), $this->kubeconfig('new.test')));

        $row = $this->rowIn($acme, $own->id);
        $this->assertSame('10.96.2.2:5000', $row->registry_address);
        $this->assertSame('10.96.1.1:5000', json_decode((string) $row->migration_old_state, true)['registry_address'] ?? null);

        $acme->run(fn () => $service->rollbackMigration(KnativeRegion::findOrFail($own->id)));

        $row = $this->rowIn($acme, $own->id);
        $this->assertSame('10.96.1.1:5000', $row->registry_address);
        $this->assertNull($row->migration_old_state);
        $this->assertNull(KnativeRegion::find($own->id), 'never in the central schema');
    }

    public function test_a_status_job_saving_the_plain_region_model_reaches_the_tenant_copies(): void
    {
        $region = $this->platformRegion();
        $acme = $this->tenantSchema('acme');
        $ops = $this->tenantSchema('ops');
        $this->assign($acme, $region);
        $this->assign($ops, $region);

        // CheckKnativeStatusJob holds an App\Models\KnativeRegion (dispatched by knative:check-status)
        $this->mock(KnativeStatusService::class, fn ($mock) => $mock->shouldReceive('checkStatus')->andReturn([
            'status' => 'ready', 'message' => 'all components ready',
            'clusterStatus' => 'connected', 'clusterMessage' => 'v1.33',
            'components' => ['operator' => 'ready', 'serving' => 'ready'],
        ]));
        $this->mock(DomainMigrateService::class, function ($mock) {
            $mock->shouldReceive('repairDomainForRegion');
            $mock->shouldReceive('completeIfResolved');
        });
        $plain = KnativeRegion::query()->findOrFail($region->id);
        $this->assertSame(KnativeRegion::class, $plain::class);
        (new CheckKnativeStatusJob($plain))->handle(app(KnativeStatusService::class));

        foreach ([$acme, $ops] as $tenant) {
            $copy = $this->rowIn($tenant, $region->id);
            $this->assertSame('ready', $copy->knative_status, "status of the copy in {$tenant->slug}");
            $this->assertSame('ready', $copy->knative_serving_status);
            $this->assertSame('connected', $copy->cluster_status);
        }

        // A plain save of the status reaches the copies, too
        KnativeRegion::query()->findOrFail($region->id)->update(['region_status' => 'maintenance', 'is_active' => false]);
        $this->assertSame('maintenance', $this->rowIn($acme, $region->id)->region_status);
    }

    public function test_every_central_region_save_syncs_once_and_only_platform_regions(): void
    {
        $region = $this->platformRegion();
        $acme = $this->tenantSchema('acme');
        $this->assign($acme, $region);

        $syncs = 0;
        Event::listen(SyncedResourceSaved::class, function () use (&$syncs) {
            $syncs++;
        });

        TenantAwareKnativeRegion::findOrFail($region->id)->update(['knative_status' => 'checking']);
        $this->assertSame(1, $syncs, 'the route-bound model syncs once');

        KnativeRegion::query()->findOrFail($region->id)->update(['knative_status' => 'ready']);
        $this->assertSame(2, $syncs, 'the plain model syncs once');
        $this->assertSame('ready', $this->rowIn($acme, $region->id)->knative_status);

        // Saves in the tenant's context (its copy, its own regions) never sync, and a tenant's own
        // region saved centrally is no platform region
        $acme->run(fn () => KnativeRegion::query()->findOrFail($region->id)->update(['knative_status_message' => 'tenant side']));
        $own = new KnativeRegion(['name' => 'own', 'project_namespace' => 'own', 'is_active' => true]);
        $own->forceFill(['owner_tenant_id' => $acme->id])->save();
        KnativeRegion::query()->findOrFail($own->id)->update(['knative_status' => 'ready']);
        $this->assertSame(2, $syncs);
        $this->assertNull($this->rowIn($acme, $own->id));
        $this->assertNotSame('tenant side', KnativeRegion::query()->findOrFail($region->id)->knative_status_message, 'one way: central → tenant');
    }

    public function test_ending_an_assignment_removes_the_copy_after_the_teardown(): void
    {
        $region = $this->platformRegion();
        $acme = $this->tenantSchema('acme');
        $this->assign($acme, $region);
        [$projectId, $gatewayId] = $this->projectWithGateway($acme, $region);

        // The detach job runs synchronously here (queue "sync")
        app(TenantController::class)->removeRegion($acme->id, $region->id);

        $this->assertNull($this->rowIn($acme, $region->id), 'copy removed');
        $this->assertNull($this->projectRegion($acme, $projectId), 'the project stays, without the region');
        $this->assertSame(0, $acme->run(fn () => DB::table('gateways')->count() + DB::table('gateway_routes')->count()));
        $this->assertContains("DELETE platform.test/apis/traefik.io/v1alpha1/namespaces/platform/ingressroutes/gw-{$gatewayId}", $this->calls);
        $this->assertNotNull(KnativeRegion::find($region->id), 'the platform region itself stays');
    }

    public function test_a_region_assigned_again_before_the_cleanup_keeps_its_copy(): void
    {
        $region = $this->platformRegion();
        $acme = $this->tenantSchema('acme');
        $this->assign($acme, $region);
        [$projectId] = $this->projectWithGateway($acme, $region);

        (new DetachRegionCleanupJob($acme, $region->id))->handle(app(KnativeDeployService::class));

        $this->assertNotNull($this->rowIn($acme, $region->id));
        $this->assertSame($region->id, $this->projectRegion($acme, $projectId));
    }

    public function test_deleting_a_platform_region_removes_every_tenants_copy(): void
    {
        $acme = $this->tenantSchema('acme');
        $ops = $this->tenantSchema('ops');
        $bound = $this->platformRegion('bound');
        $plain = $this->platformRegion('plain');
        foreach ([$bound, $plain] as $region) {
            $this->assign($acme, $region);
            $this->assign($ops, $region);
        }
        [$projectId] = $this->projectWithGateway($acme, $bound);

        // The region API deletes the route-bound model; other code may use App\Models\KnativeRegion
        TenantAwareKnativeRegion::findOrFail($bound->id)->delete();
        KnativeRegion::findOrFail($plain->id)->delete();

        foreach ([$acme, $ops] as $tenant) {
            $this->assertNull($this->rowIn($tenant, $bound->id), "copy of bound in {$tenant->slug}");
            $this->assertNull($this->rowIn($tenant, $plain->id), "copy of plain in {$tenant->slug}");
        }
        $this->assertNull($this->projectRegion($acme, $projectId));
        $this->assertSame(0, $acme->run(fn () => DB::table('gateways')->count()));
    }

    public function test_a_region_created_in_a_tenant_is_its_own(): void
    {
        $acme = $this->tenantSchema('acme');
        $platform = $this->platformRegion();
        $this->assign($acme, $platform);

        // The region API: App\Models\KnativeRegion::create() in the tenant's context
        $own = $acme->run(fn () => KnativeRegion::create(['name' => 'own', 'project_namespace' => 'own', 'kubeconfig' => $this->kubeconfig('own.test')]));

        $row = $this->rowIn($acme, $own->id);
        $this->assertSame($acme->id, $row->owner_tenant_id);
        $this->assertFalse((bool) $row->is_synced);

        // Central regions and synced copies are not touched
        $this->assertNull($platform->fresh()->owner_tenant_id);
        $copy = $this->rowIn($acme, $platform->id);
        $this->assertNull($copy->owner_tenant_id);
        $this->assertTrue((bool) $copy->is_synced);

        // A tenant deleting its own region leaves the others alone
        $acme->run(fn () => KnativeRegion::findOrFail($own->id)->delete());
        $this->assertNull($this->rowIn($acme, $own->id));
        $this->assertNotNull($this->rowIn($acme, $platform->id));
    }

    public function test_the_upgrade_gives_existing_own_regions_their_owner(): void
    {
        $acme = $this->tenantSchema('acme');
        $platform = $this->platformRegion();
        $this->assign($acme, $platform);

        // Created before the owner was set: unsynced, owner null, central settings without owner
        $legacyId = (string) Str::ulid();
        $acme->run(fn () => DB::table('knative_regions')->insert(['id' => $legacyId, 'name' => 'legacy', 'is_synced' => false, 'created_at' => now(), 'updated_at' => now()]));
        DB::table('region_supply_chain_settings')->insert(['knative_region_id' => $legacyId, 'owner_tenant_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('region_tracing_configs')->insert(['id' => (string) Str::ulid(), 'region_id' => $legacyId, 'tenant_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('region_tracing_configs')->insert(['id' => (string) Str::ulid(), 'region_id' => $platform->id, 'tenant_id' => null, 'created_at' => now(), 'updated_at' => now()]);

        $migration = require base_path('Modules/Tenancy/Database/TenantMigrations/0056_set_owner_tenant_id_on_own_regions.php');
        $acme->run(fn () => $migration->up());
        $acme->run(fn () => $migration->up()); // re-running changes nothing

        $this->assertSame($acme->id, $this->rowIn($acme, $legacyId)->owner_tenant_id);
        $this->assertNull($this->rowIn($acme, $platform->id)->owner_tenant_id, 'synced copy untouched');
        $this->assertSame($acme->id, DB::table('region_supply_chain_settings')->where('knative_region_id', $legacyId)->value('owner_tenant_id'));
        $this->assertSame($acme->id, DB::table('region_tracing_configs')->where('region_id', $legacyId)->value('tenant_id'));
        $this->assertNull(DB::table('region_tracing_configs')->where('region_id', $platform->id)->value('tenant_id'));
    }
}
