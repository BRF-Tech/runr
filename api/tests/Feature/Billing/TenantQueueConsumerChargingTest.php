<?php

namespace Tests\Feature\Billing;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Services\KnativeDeployService;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\RegionOverride;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Models\TenantRegionOverride;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\QueueConsumerCharging;
use Modules\Billing\Support\Period;
use Modules\Tenancy\Jobs\DetachRegionCleanupJob;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalRegion;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Support\TenantRegionShares;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\WiresTenancy;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\Support\ActingTenant;
use Tests\TestCase;

/**
 * Queue consumers on a platform region shared with a tenant (docs/billing.md#queue-consumers):
 * charged only when the region charges them, the platform (the region's owner) has not switched it
 * off for the tenant — which the platform's bill to the tenant and the tenant's own bills follow —
 * and, for the tenant's own bills, the tenant has not switched it off for itself.
 */
class TenantQueueConsumerChargingTest extends TestCase
{
    use CallsMcp, EnablesBilling, RefreshDatabase, WiresTenancy;

    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0007_add_user_id_to_notifications.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0009_create_usage_records_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0015_create_notification_preferences_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0022_add_agent_token_hash_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0024_add_function_tls_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0035_add_wasm_sandbox_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0035_create_bindings_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0045_add_gpu_columns.php',
        'Modules/Tenancy/Database/TenantMigrations/0052_add_capacity_admission_columns.php',
        'Modules/Tenancy/Database/TenantMigrations/0058_add_owner_and_stop_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0059_add_created_by_to_usage_records.php',
        'Modules/Billing/Database/TenantMigrations/0060_create_billing_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0062_add_deployment_spans_and_stop_level.php',
        'Modules/Billing/Database/TenantMigrations/0076_add_queue_consumer_charging.php',
    ];

    private Tenant $tenant;

    private KnativeRegion $region;

    private User $tenantOwner;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');

        $this->setUpTenancy();
        config(['tenancy.enabled' => true, 'app.key' => config('app.key') ?: 'base64:'.base64_encode(random_bytes(32))]);
        $this->setUpBilling();

        // The test tenant context (X-Test-Tenant) right after authentication, as in an install
        $router = $this->app['router'];
        $priority = $router->middlewarePriority;
        $at = array_search(AuthenticatesRequests::class, $priority, true);
        array_splice($priority, $at === false ? 0 : $at + 1, 0, [ActingTenant::class]);
        $router->middlewarePriority = $priority;
        $router->pushMiddlewareToGroup('api', ActingTenant::class);

        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(4)), self::TENANT_MIGRATIONS);
        $this->region = KnativeRegion::query()->create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'is_demo' => true,
            'total_cpu_m' => 64000, 'total_memory_mb' => 262144,
        ]);
        // The platform's price, and the region charges queue consumers (the platform's setting)
        $this->priceVersion($this->region->id, '2026-01-01', ['cpu_vcpu_hour' => 1.00, 'memory_gb_hour' => 0]);
        RegionSetting::query()->create(['region_id' => $this->region->id, 'billing_type' => 'payg', 'charge_queue_consumers' => true]);

        DB::connection('central')->table('tenant_regions')->insert([
            'tenant_id' => $this->tenant->id, 'knative_region_id' => $this->region->id, 'cpu_quota_m' => 8000, 'memory_quota_mb' => 16384,
            'status' => TenantRegionShares::ACTIVE, 'offered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tenant->run(fn () => TenantLocalRegion::query()->create([
            'id' => $this->region->id, 'name' => $this->region->name, 'project_namespace' => 'eu-1',
            'is_active' => true, 'is_demo' => true, 'is_synced' => true, 'total_cpu_m' => 64000, 'total_memory_mb' => 262144,
        ]));

        $this->tenantOwner = User::factory()->create(['username' => 'owner-'.Str::lower(Str::random(6))]);
        TenantUser::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->tenantOwner->id, 'role' => 'owner']);
        $this->platformAdmin = User::factory()->create(['username' => 'padmin-'.Str::lower(Str::random(6))]);
        $this->platformAdmin->forceFill(['is_admin' => true])->save();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        $this->tearDownTenancy();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(): array
    {
        return ['X-Test-Tenant' => (string) $this->tenant->id, 'Accept' => 'application/json'];
    }

    /** A queue project of the tenant with 10 vCPU-hours of its consumer on 5 October, in the tenant and its central copy. */
    private function projectWithConsumer(): Project
    {
        $project = $this->tenant->run(fn () => Project::withoutEvents(function () {
            $project = new Project;
            $project->forceFill([
                'name' => 'jobs', 'slug' => 'jobs', 'service_type' => 'caas', 'knative_region_id' => $this->region->id,
                'created_by' => $this->tenantOwner->id, 'owner_id' => $this->tenantOwner->id,
            ])->save();

            return $project;
        }));

        $row = [
            'id' => (string) Str::ulid(), 'project_id' => $project->id, 'deployment_id' => (string) Str::ulid(),
            'region_id' => $this->region->id, 'record_date' => '2026-10-05', 'scale_mode' => 'custom_scale', 'entry_point' => 'queue',
            'source' => 'queue_consumer', 'session_count' => 0, 'reserved_cpu_m' => 50, 'reserved_memory_mb' => 64, 'min_scale' => 1,
            'total_duration_ms' => 200 * 3_600_000, 'total_cpu_mc_ms' => 10 * 3_600_000_000, 'total_memory_mib_ms' => 0,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ];
        $this->tenant->run(fn () => DB::table('usage_records')->insert($row));
        DB::connection('central')->table('usage_records')->insert($row + ['tenant_id' => (string) $this->tenant->id]);

        return $project;
    }

    /** [the tenant's bill to its project, the platform's bill to the tenant] */
    private function totals(Project $project): array
    {
        $tenantSide = $this->tenant->run(function () use ($project) {
            app(PriceBook::class)->flush();
            app(QueueConsumerCharging::class)->flush();

            return app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10'))['total'];
        });

        app(PriceBook::class)->flush();
        app(QueueConsumerCharging::class)->flush();
        $platformSide = app(BillCalculator::class)->forTenant((string) $this->tenant->id, Period::fromKey('2026-10'))['total'];

        return [(float) $tenantSide, (float) $platformSide];
    }

    public function test_the_platform_switches_queue_consumer_charging_off_for_one_tenant(): void
    {
        $project = $this->projectWithConsumer();

        // The region charges them: 10 vCPU-hours at 1.00 on both bills
        [$tenantSide, $platformSide] = $this->totals($project);
        $this->assertEqualsWithDelta(10.0, $tenantSide, 0.001);
        $this->assertEqualsWithDelta(10.0, $platformSide, 0.001);

        // The platform lists the tenants of the region and switches it off for this one
        $this->actingAs($this->platformAdmin)->getJson("/api/billing/regions/{$this->region->id}/organizations")->assertOk()
            ->assertJsonPath('charge_queue_consumers', true)
            ->assertJsonPath('data.0.tenant.id', (string) $this->tenant->id)
            ->assertJsonPath('data.0.share_status', TenantRegionShares::ACTIVE)
            ->assertJsonPath('data.0.queue_consumers_override', null)
            ->assertJsonPath('data.0.queue_consumers_charged', true);
        $this->actingAs($this->platformAdmin)->putJson("/api/billing/regions/{$this->region->id}/organizations/{$this->tenant->slug}", ['charge' => false])->assertOk()
            ->assertJsonPath('data.queue_consumers_override', false)
            ->assertJsonPath('data.queue_consumers_charged', false);
        $this->assertFalse(TenantRegionOverride::query()->where('tenant_id', $this->tenant->id)->sole()->charge_queue_consumers);

        // Neither the platform's bill to the tenant nor the tenant's own bill charges them now
        $this->assertSame([0.0, 0.0], $this->totals($project));
        $this->actingAs($this->tenantOwner)->getJson('/api/billing/regions', $this->headers())->assertOk()
            ->assertJsonPath('data.0.charge_queue_consumers', true)
            ->assertJsonPath('data.0.queue_consumers_owner_override', false)
            ->assertJsonPath('data.0.queue_consumers_charged', false);

        // The tenant cannot switch on what the platform switched off for it
        $this->actingAs($this->tenantOwner)->putJson("/api/billing/regions/{$this->region->id}/queue-consumers", ['charge' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.queue_consumers_charged', false);

        // Back to the region's setting
        $this->actingAs($this->platformAdmin)->putJson("/api/billing/regions/{$this->region->id}/organizations/{$this->tenant->id}", ['charge' => null])->assertOk()
            ->assertJsonPath('data.queue_consumers_override', null);
        $this->assertNull(TenantRegionOverride::query()->where('tenant_id', $this->tenant->id)->first());
        $this->assertSame([10.0, 10.0], array_map(fn ($v) => round($v, 3), $this->totals($project)));
    }

    public function test_the_tenants_own_choice_applies_to_its_own_bills_only(): void
    {
        $project = $this->projectWithConsumer();

        $this->actingAs($this->tenantOwner)->putJson("/api/billing/regions/{$this->region->id}/queue-consumers", ['charge' => false], $this->headers())->assertOk()
            ->assertJsonPath('data.queue_consumers_override', false)
            ->assertJsonPath('data.queue_consumers_charged', false);

        // The tenant does not charge its project; the platform still charges the tenant
        [$tenantSide, $platformSide] = $this->totals($project);
        $this->assertEqualsWithDelta(0.0, $tenantSide, 0.001);
        $this->assertEqualsWithDelta(10.0, $platformSide, 0.001);
    }

    public function test_only_platform_administrators_set_it_and_only_on_platform_regions(): void
    {
        $region = $this->region->id;

        // A tenant member never reaches the platform's per-organization choice
        $this->actingAs($this->tenantOwner)->getJson("/api/billing/regions/{$region}/organizations", $this->headers())->assertForbidden();
        $this->actingAs($this->tenantOwner)->putJson("/api/billing/regions/{$region}/organizations/{$this->tenant->id}", ['charge' => false], $this->headers())->assertForbidden();

        // Unknown regions and tenants, a missing charge
        $this->actingAs($this->platformAdmin)->getJson('/api/billing/regions/no-such-region/organizations')->assertNotFound();
        $this->actingAs($this->platformAdmin)->putJson("/api/billing/regions/{$region}/organizations/no-such-tenant", ['charge' => false])->assertNotFound();
        $this->actingAs($this->platformAdmin)->putJson("/api/billing/regions/{$region}/organizations/{$this->tenant->id}", [])->assertStatus(422);

        // A region a tenant owns is not the platform's to set
        $owned = KnativeRegion::query()->create(['name' => 'acme-dc', 'project_namespace' => 'acme-dc', 'is_active' => true]);
        $owned->forceFill(['owner_tenant_id' => (string) $this->tenant->id])->saveQuietly();
        $this->actingAs($this->platformAdmin)->getJson("/api/billing/regions/{$owned->id}/organizations")->assertNotFound();
    }

    public function test_platform_administrators_set_the_choice_per_tenant_through_mcp(): void
    {
        $token = $this->mcpToken($this->platformAdmin);
        $session = $this->mcpInitialize($token);

        $listed = $this->mcpTool($token, $session, 'billing_region_organizations', ['region' => $this->region->id]);
        $this->assertFalse($listed['isError'] ?? false, json_encode($listed));
        $this->assertSame((string) $this->tenant->id, $listed['structuredContent']['organizations'][0]['tenant']['id']);
        $this->assertTrue($listed['structuredContent']['organizations'][0]['queue_consumers_charged']);

        $set = $this->mcpTool($token, $session, 'billing_region_organization_set', ['region' => $this->region->id, 'tenant' => $this->tenant->slug, 'charge' => false]);
        $this->assertFalse($set['isError'] ?? false, json_encode($set));
        $this->assertFalse($set['structuredContent']['organization']['queue_consumers_charged']);
        $this->assertFalse(TenantRegionOverride::on('central')->where('tenant_id', (string) $this->tenant->id)->sole()->charge_queue_consumers);

        // The tenant's owner neither sees nor calls them
        $ownerToken = $this->mcpToken($this->tenantOwner);
        $ownerSession = $this->mcpInitialize($ownerToken);
        $this->assertNotContains('billing_region_organization_set', $this->mcpToolNames($ownerToken, $ownerSession));
    }

    public function test_a_detached_region_takes_the_choices_for_the_tenant_along(): void
    {
        TenantRegionOverride::on('central')->create(['tenant_id' => (string) $this->tenant->id, 'region_id' => $this->region->id, 'charge_queue_consumers' => false]);
        $this->tenant->run(fn () => RegionOverride::query()->create(['region_id' => $this->region->id, 'charge_queue_consumers' => false]));
        $other = Tenant::query()->create(['name' => 'Globex', 'slug' => 'globex'.Str::lower(Str::random(4))]);
        TenantRegionOverride::on('central')->create(['tenant_id' => (string) $other->id, 'region_id' => $this->region->id, 'charge_queue_consumers' => false]);

        // The platform ends the share; the cleanup job runs (no kubeconfig: nothing to tear down)
        DB::connection('central')->table('tenant_regions')->where('tenant_id', $this->tenant->id)->delete();
        (new DetachRegionCleanupJob($this->tenant, $this->region->id))->handle(app(KnativeDeployService::class));

        $this->assertSame(0, TenantRegionOverride::on('central')->where('tenant_id', (string) $this->tenant->id)->count());
        $this->assertSame(0, $this->tenant->run(fn () => RegionOverride::query()->count()), 'the tenant\'s own choice for the region');
        $this->assertSame(1, TenantRegionOverride::on('central')->where('tenant_id', (string) $other->id)->count(), 'another tenant\'s choice stays');
    }

    public function test_a_deleted_region_takes_its_choices_along(): void
    {
        $kept = KnativeRegion::query()->create(['name' => 'us-1', 'project_namespace' => 'us-1', 'is_active' => true, 'is_demo' => true]);
        TenantRegionOverride::on('central')->create(['tenant_id' => (string) $this->tenant->id, 'region_id' => $this->region->id, 'charge_queue_consumers' => false]);
        TenantRegionOverride::on('central')->create(['tenant_id' => (string) $this->tenant->id, 'region_id' => $kept->id, 'charge_queue_consumers' => false]);
        RegionOverride::query()->create(['region_id' => $this->region->id, 'charge_queue_consumers' => false]);
        $this->tenant->run(fn () => RegionOverride::query()->create(['region_id' => $this->region->id, 'charge_queue_consumers' => false]));

        // The platform's choices for the region go with it, centrally; a tenant's copy of the
        // region takes the tenant's own choice along
        $this->region->delete();
        $this->tenant->run(fn () => TenantLocalRegion::removeCopy($this->region->id));

        $this->assertSame(0, TenantRegionOverride::on('central')->where('region_id', $this->region->id)->count());
        $this->assertSame(1, TenantRegionOverride::on('central')->where('region_id', $kept->id)->count());
        $this->assertNull(RegionOverride::query()->find($this->region->id));
        $this->assertSame(0, $this->tenant->run(fn () => RegionOverride::query()->count()));
    }
}
