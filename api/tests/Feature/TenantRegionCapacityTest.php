<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Http\Controllers\Api\RegionCapacityController;
use App\Mail\ResourceThresholdMail;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Models\XaasConfig;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\StoppedCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Services\TenantQuotaService;
use Modules\Tenancy\Services\TenantResourceThresholdService;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Region capacity with the Tenancy module: tenant schemas get the overcommit factors (and the
 * deployed RuntimeClass columns) from TenantMigrations/0052 with the defaults, and the capacity
 * alerts of a platform region add up what every tenant runs there — and what every tenant's
 * stopped projects would hold again. A stopped project holds nothing of its organization's quota
 * either, and is admitted against it again.
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

    /** A scale-to-zero function of 250m (350m with the queue-proxy) in the current schema. */
    private function tenantFunction(KnativeRegion $region, string $name, array $attributes = []): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $region->id] + $attributes)->saveQuietly();
        XaasConfig::create([
            'project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler',
            'cpu' => '250m', 'memory_mb' => 128, 'trigger_type' => 'http', 'min_scale' => 0, 'max_scale' => 2,
        ]);

        return $project->fresh();
    }

    public function test_the_platform_alarm_counts_every_tenants_stopped_projects(): void
    {
        Mail::fake();
        Event::fake([NotificationCreated::class]);
        Cache::flush();
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_admin' => true])->save();

        // 1000m × 3 = 3000m of FaaS budget
        $region = KnativeRegion::create([
            'name' => 'shared', 'project_namespace' => 'shared', 'is_active' => true, 'cluster_status' => 'online',
            'total_cpu_m' => 1000, 'total_memory_mb' => 100000,
        ]);

        // acme runs 2 and stopped 4 (6 × 350m), globex runs 2 and stopped 1: 1400m held, 3150m with them back
        foreach (['acme' => [2, 4], 'globex' => [2, 1]] as $slug => [$running, $stopped]) {
            $this->createTenantWithSchema($slug)->run(function () use ($region, $slug, $running, $stopped) {
                foreach (range(1, $running) as $i) {
                    $this->tenantFunction($region, "{$slug}-fn-{$i}");
                }
                foreach (range(1, $stopped) as $i) {
                    $this->tenantFunction($region, "{$slug}-old-{$i}", ['stopped_at' => now(), 'stopped_by' => 'admin', 'stop_reason' => 'unpaid']);
                }
            });
        }

        app(TenantResourceThresholdService::class)->checkAllRegions();

        $alarm = Notification::where('type', 'resource_threshold')->get()->sole();
        $this->assertSame('with_stopped', $alarm->data['basis']);
        $this->assertSame('critical', $alarm->level);
        $this->assertEquals(105.0, $alarm->data['percentage']);
        $this->assertSame(5, $alarm->data['stopped_projects']);
        $this->assertSame(150, $alarm->data['exceeded_by']);
        $this->assertContains('acme / acme-old-1', array_column($alarm->data['projects'], 'name'));
        $this->assertContains('globex / globex-old-1', array_column($alarm->data['projects'], 'name'));
        // the platform's: in the central schema, by mail to the platform administrators
        $this->assertNull($alarm->tenant_id);
        Mail::assertQueued(ResourceThresholdMail::class, fn (ResourceThresholdMail $mail) => $mail->withStopped && $mail->hasTo($platformAdmin->email));
    }

    public function test_a_stopped_project_holds_nothing_of_its_organizations_quota_and_is_admitted_again(): void
    {
        config(['tenancy.enabled' => true]);
        $region = KnativeRegion::create([
            'name' => 'quota', 'project_namespace' => 'quota', 'is_active' => true,
            'total_cpu_m' => 16000, 'total_memory_mb' => 100000,
        ]);
        $acme = $this->createTenantWithSchema('acme');
        // 1000m × 3 = 3000m of FaaS budget for acme on the region
        $acme->regions()->syncWithoutDetaching([$region->id => ['cpu_quota_m' => 1000, 'memory_quota_mb' => 0]]);

        $acme->run(function () use ($acme, $region) {
            DB::table('knative_regions')->insert(['id' => $region->id, 'name' => 'quota', 'is_synced' => true, 'total_cpu_m' => 16000, 'total_memory_mb' => 100000]);
            $copy = KnativeRegion::findOrFail($region->id);
            $quota = app(TenantQuotaService::class);

            $api = $this->tenantFunction($copy, 'api', ['stopped_at' => now(), 'stopped_by' => 'admin', 'stop_reason' => 'unpaid']);
            foreach (range(1, 8) as $i) {
                $this->tenantFunction($copy, "fn-{$i}");
            }

            // 8 × 350m: the stopped project holds none of the quota
            $this->assertSame(2800, $quota->currentDemand($copy)['burst']['cpu_m']);
            $this->assertTrue($quota->checkReservation($acme, $copy, ['hard' => ['cpu_m' => 0, 'memory_mb' => 0], 'burst' => ['cpu_m' => 200, 'memory_mb' => 0]])['allowed']);

            // resumed (awaiting admission): its 350m do not fit the 200m left of the quota
            $api->forceFill(['stopped_at' => null, 'stopped_by' => null, 'stop_reason' => null, StoppedCapacity::COLUMN => now()])->saveQuietly();
            $refusal = StoppedCapacity::refusal($api->fresh());
            $this->assertSame(StoppedCapacity::QUOTA_EXCEEDED, $refusal['code'] ?? null);
            $this->assertSame('faas', $refusal['budget']);
            $this->assertSame(200, $refusal['remaining_cpu_m']);
            $this->assertSame(['cpu_m' => ['budget' => 'faas', 'needed' => 350, 'available' => 200, 'missing' => 150]], $refusal['shortfall']);
            $this->assertStringContainsString("The organization's quota on region quota has no room for this project again", $refusal['message']);

            // room again: admitted, it holds its share of the quota
            DB::table('projects')->where('name', 'fn-1')->delete();
            StoppedCapacity::readmit($api->fresh());
            $this->assertNull($api->fresh()->capacity_released_at);
            $this->assertSame(2800, $quota->currentDemand($copy)['burst']['cpu_m']);
        });
    }

    /** The region tables and the notifications (TenantMigrations/0007): the capacity alarm writes into a tenant's schema. */
    private const WITH_NOTIFICATIONS = [...self::TENANT_REGION_MIGRATIONS, 'Modules/Tenancy/Database/TenantMigrations/0007_add_user_id_to_notifications.php'];

    /** A member of $tenant with $role (central tenant_users). */
    private function memberOf($tenant, string $role): User
    {
        $user = User::factory()->create();
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_the_card_of_a_platform_region_shows_what_the_alarm_measures(): void
    {
        Mail::fake();
        Event::fake([NotificationCreated::class]);
        Cache::flush();
        $region = KnativeRegion::create([
            'name' => 'shared', 'project_namespace' => 'shared', 'is_active' => true, 'cluster_status' => 'online',
            'total_cpu_m' => 1000, 'total_memory_mb' => 100000,
        ]);
        // the platform's own project (central schema): 350m
        $this->tenantFunction($region, 'platform-api');

        // acme runs 3 and stopped 1, globex runs 2: 6 × 350m = 2100m of 3000m (70 %), 2450m with the stopped one back
        $tenants = [];
        foreach (['acme' => [3, 1], 'globex' => [2, 0]] as $slug => [$running, $stopped]) {
            $tenants[$slug] = $this->createTenantWithSchema($slug, self::WITH_NOTIFICATIONS);
            $tenants[$slug]->run(function () use ($region, $slug, $running, $stopped) {
                foreach (range(1, $running) as $i) {
                    $this->tenantFunction($region, "{$slug}-fn-{$i}");
                }
                foreach ($stopped > 0 ? range(1, $stopped) : [] as $i) {
                    $this->tenantFunction($region, "{$slug}-old-{$i}", ['stopped_at' => now(), 'stopped_by' => 'admin', 'stop_reason' => 'unpaid']);
                }
            });
        }

        $service = app(TenantResourceThresholdService::class);
        $data = (new RegionCapacityController($service))->show($region)->getData(true)['data'];

        $this->assertSame(2100, $data['cpu_m']['faas_used'], 'every tenant and the platform, as the alarm counts');
        $this->assertEquals(70.0, $data['cpu_m']['utilization']);
        $this->assertSame(1, $data['with_stopped']['projects']);
        $this->assertSame(2450, $data['with_stopped']['cpu_m']['faas_used']);
        $this->assertSame('acme / acme-old-1', $data['with_stopped']['stopped_projects'][0]['name']);
        $this->assertSame($service->measure($region)['usage']['burst']['cpu_m'], $data['cpu_m']['faas_used'], 'the same measure');

        // a check after a tenant's deploy there is the platform's: measured across every tenant, in the central schema
        $tenants['acme']->run(function () use ($region, $service) {
            DB::table('knative_regions')->insert(['id' => $region->id, 'name' => 'shared', 'is_synced' => true, 'is_active' => true, 'total_cpu_m' => 1000, 'total_memory_mb' => 100000]);
            $service->checkRegion(KnativeRegion::findOrFail($region->id));
            $this->assertSame(0, Notification::query()->count(), 'nothing in the tenant\'s schema');
        });
        $alarms = Notification::query()->where('type', 'resource_threshold')->get();
        $this->assertSame(['critical', 'warning'], $alarms->pluck('level')->sort()->values()->all());
        $this->assertEquals(70.0, $alarms->firstWhere('level', 'warning')->data['percentage']);
        $this->assertSame('with_stopped', $alarms->firstWhere('level', 'critical')->data['basis']);
    }

    public function test_the_card_of_a_tenants_own_region_seen_from_the_platform_counts_the_tenants_schema(): void
    {
        $acme = $this->createTenantWithSchema('acme');
        $region = KnativeRegion::create([
            'name' => 'acme-dc', 'project_namespace' => 'acme-dc', 'is_active' => true,
            'total_cpu_m' => 1000, 'total_memory_mb' => 100000,
        ]);
        $region->forceFill(['owner_tenant_id' => $acme->id])->save();
        $acme->run(function () use ($region) {
            DB::table('knative_regions')->insert(['id' => $region->id, 'name' => 'acme-dc', 'is_synced' => false, 'is_active' => true, 'total_cpu_m' => 1000, 'total_memory_mb' => 100000]);
            $copy = KnativeRegion::findOrFail($region->id);
            $this->tenantFunction($copy, 'intranet');
            $this->tenantFunction($copy, 'old', ['stopped_at' => now(), 'stopped_by' => 'admin', 'stop_reason' => 'paused']);
        });

        $measure = app(TenantResourceThresholdService::class)->measure($region);

        $this->assertSame(350, $measure['usage']['burst']['cpu_m']);
        $this->assertSame('old', $measure['stopped']['projects'][0]['name']);
    }

    public function test_a_tenants_own_region_tells_the_tenant_who_manages_its_regions(): void
    {
        Mail::fake();
        Event::fake([NotificationCreated::class]);
        Cache::flush();
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_admin' => true])->save();

        $acme = $this->createTenantWithSchema('acme', self::WITH_NOTIFICATIONS);
        $owner = $this->memberOf($acme, 'owner');
        $admin = $this->memberOf($acme, 'admin');
        $quiet = $this->memberOf($acme, 'admin');
        $developer = $this->memberOf($acme, 'developer');

        $acme->run(function () use ($quiet) {
            // the tenant's own region: 7 × 350m = 2450m of 3000m (82 %)
            DB::table('knative_regions')->insert(['id' => '01OWNREGION', 'name' => 'acme-dc', 'is_synced' => false, 'is_active' => true, 'cluster_status' => 'online', 'total_cpu_m' => 1000, 'total_memory_mb' => 100000]);
            $own = KnativeRegion::findOrFail('01OWNREGION');
            foreach (range(1, 7) as $i) {
                $this->tenantFunction($own, "fn-{$i}");
            }
            // the organization's preferences, in its schema: one admin switched the capacity mail off
            Schema::create('notification_preferences', function ($table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('type');
                $table->string('channel');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
            DB::table('notification_preferences')->insert(['user_id' => $quiet->id, 'type' => 'resource_threshold', 'channel' => 'email', 'enabled' => false]);
        });

        app(TenantResourceThresholdService::class)->checkAllRegions();

        // the tenant's notification, in its schema; none in the platform's
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame('critical', $acme->run(fn () => Notification::query()->where('type', 'resource_threshold')->sole()->level));

        // the mail: the members who manage the tenant's regions, not the platform's administrators
        Mail::assertQueued(ResourceThresholdMail::class, 1);
        Mail::assertQueued(ResourceThresholdMail::class, fn (ResourceThresholdMail $mail) => $mail->hasTo($owner->email) && $mail->hasTo($admin->email)
            && ! $mail->hasTo($quiet->email) && ! $mail->hasTo($developer->email) && ! $mail->hasTo($platformAdmin->email));
    }
}
