<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Services\Helpers\RegionCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Services\TenantResourceThresholdService;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Region capacity with the Tenancy module: tenant schemas get the overcommit factors (and the
 * deployed RuntimeClass columns) from TenantMigrations/0052 with the defaults, and the capacity
 * alerts of a platform region add up what every tenant runs there.
 */
class TenantRegionCapacityTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_the_tenant_migration_adds_the_columns_with_their_defaults(): void
    {
        $tenant = $this->createTenantWithSchema('acme', [
            'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
            'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        ]);

        $tenant->run(function () {
            $this->assertFalse(Schema::hasColumn('knative_regions', 'faas_cpu_overcommit'));

            // a tenant-owned region of the previous release
            DB::table('knative_regions')->insert(['id' => '01OWN', 'name' => 'own', 'is_synced' => false, 'total_cpu_m' => 2000, 'total_memory_mb' => 4096]);

            // twice: the second run is a no-op
            foreach ([1, 2] as $_) {
                (require base_path('Modules/Tenancy/Database/TenantMigrations/0052_add_capacity_admission_columns.php'))->up();
            }

            $region = KnativeRegion::find('01OWN');
            $this->assertSame(3.0, $region->faas_cpu_overcommit);
            $this->assertSame(1.5, $region->faas_memory_overcommit);
            $this->assertSame(6000, RegionCapacity::limits([$region])['overcommit']['cpu_m']);
            $this->assertTrue(Schema::hasColumn('projects', 'deployed_runtime_class'));

            // the factors are writable on the copy (central → tenant sync, tenant-owned regions)
            $region->update(['faas_cpu_overcommit' => 2, 'faas_memory_overcommit' => 1]);
            $this->assertSame([2.0, 1.0], [$region->fresh()->faas_cpu_overcommit, $region->fresh()->faas_memory_overcommit]);
        });
    }

    public function test_platform_region_alerts_add_up_what_every_tenant_runs(): void
    {
        Mail::fake();
        Event::fake([NotificationCreated::class]);

        $region = KnativeRegion::create([
            'name' => 'shared', 'project_namespace' => 'shared', 'is_active' => true, 'cluster_status' => 'online',
            'total_cpu_m' => 1000, 'total_memory_mb' => 100000,
        ]);

        // one always-on project per tenant: 300m + 100m queue-proxy each
        foreach (['acme', 'globex'] as $slug) {
            $this->createTenantWithSchema($slug)->run(function () use ($region, $slug) {
                $project = Project::create(['name' => "{$slug}-api", 'service_type' => 'xaas', 'runtime' => 'python']);
                $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
                XaasConfig::create([
                    'project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler',
                    'cpu' => '300m', 'memory_mb' => 128, 'trigger_type' => 'http', 'min_scale' => 1, 'max_scale' => 2,
                ]);
            });
        }

        app(TenantResourceThresholdService::class)->checkAllRegions();

        // 800m always-on of the 1000m capacity: 80 % — critical
        $cpu = Notification::where('type', 'resource_threshold')->get()->firstWhere('data.resource_type', 'cpu');
        $this->assertNotNull($cpu);
        $this->assertSame('critical', $cpu->level);
        $this->assertEquals(80.0, $cpu->data['percentage']);
        $this->assertNull(Notification::where('type', 'resource_threshold')->get()->firstWhere('data.resource_type', 'memory'));
    }
}
