<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRegionMutable;
use App\Models\KnativeRegion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Tenancy\Http\Middleware\EnsureTenantRegionMutable;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * In tenant context route model binding resolves the tenant's synced copy of a platform region
 * (kubeconfig resolved from central), so any tenant user could call region-mutating endpoints on
 * it — e.g. PUT function-tls replaced the certificate served for every tenant's functions.
 * `region.mutable` now guards every region-mutating route.
 */
class RegionMutableMiddlewareTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $tenant;

    private string $syncedRegionId;

    private string $ownRegionId;

    protected function setUp(): void
    {
        parent::setUp();

        // Central connection and tenancy events (suite runs with TENANCY_ENABLED off)
        $this->setUpTenancy();

        // What TenancyServiceProvider binds when tenancy is enabled
        $this->app->bind(EnsureRegionMutable::class, EnsureTenantRegionMutable::class);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->tenant->database()->manager()->createDatabase($this->tenant);

        $this->tenant->run(function () {
            (require base_path('Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php'))->up();
            (require base_path('Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php'))->up();
            // KnativeRegion's attribute defaults (prometheus_managed / loki_managed) are inserted
            (require base_path('Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php'))->up();

            // Synced copy of a platform region: no kubeconfig of its own (resolved from central)
            $synced = new KnativeRegion(['name' => 'platform', 'project_namespace' => 'fn']);
            $synced->forceFill(['is_synced' => true])->save();
            $this->syncedRegionId = $synced->id;

            $own = new KnativeRegion(['name' => 'own', 'project_namespace' => 'own', 'kubeconfig' => 'apiVersion: v1']);
            $own->forceFill(['is_synced' => false, 'owner_tenant_id' => $this->tenant->id])->save();
            $this->ownRegionId = $own->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $this->tenant->database()->manager()->deleteDatabase($this->tenant);
        $this->tearDownTenancy();

        parent::tearDown();
    }

    private function tenantUser(string $role): User
    {
        $user = User::factory()->create(['username' => 'u'.Str::random(8)]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['username' => 'u'.Str::random(8)]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_tenant_user_cannot_change_a_platform_region(): void
    {
        $owner = $this->tenantUser('owner');
        tenancy()->initialize($this->tenant);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/knative-regions/{$this->syncedRegionId}/function-tls", ['mode' => 'console'])
            ->assertForbidden()
            ->assertJsonPath('message', __('api.region.platform_region_read_only'));

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/knative-regions/{$this->syncedRegionId}/kubeconfig")
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/knative-regions/{$this->syncedRegionId}")
            ->assertForbidden();

        $this->assertNotNull(KnativeRegion::find($this->syncedRegionId), 'platform region copy must be untouched');
    }

    public function test_tenant_owner_may_change_its_own_region(): void
    {
        $owner = $this->tenantUser('owner');
        tenancy()->initialize($this->tenant);

        // Past the middleware: the request reaches validation
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/knative-regions/{$this->ownRegionId}/function-tls", [])
            ->assertUnprocessable();

        $this->actingAs($owner, 'sanctum')
            ->get("/api/knative-regions/{$this->ownRegionId}/kubeconfig")
            ->assertOk();
    }

    public function test_tenant_member_cannot_change_the_tenants_own_region(): void
    {
        $member = $this->tenantUser('member');
        tenancy()->initialize($this->tenant);

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/knative-regions/{$this->ownRegionId}/function-tls", [])
            ->assertForbidden()
            ->assertJsonPath('message', __('api.region.region_admin_required'));
    }

    public function test_platform_admin_may_change_a_platform_region_in_tenant_context(): void
    {
        $admin = $this->platformAdmin();
        tenancy()->initialize($this->tenant);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/knative-regions/{$this->syncedRegionId}/function-tls", [])
            ->assertUnprocessable();
    }

    public function test_outside_tenant_context_only_platform_admins(): void
    {
        $user = User::factory()->create(['username' => 'u'.Str::random(8)]);
        $admin = $this->platformAdmin();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/knative-regions', [])
            ->assertForbidden()
            ->assertJsonPath('message', __('api.region.platform_admin_required'));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/knative-regions', [])
            ->assertUnprocessable();
    }

    public function test_every_region_mutating_route_carries_the_middleware(): void
    {
        $mutating = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/knative-regions')
                && array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
            // Region-less previews run against a kubeconfig in the request body, not a stored region
            ->reject(fn ($r) => in_array($r->uri(), [
                'api/knative-regions/validate-kubeconfig',
                'api/knative-regions/preview-nodes',
                'api/knative-regions/check-namespace-preview',
                'api/knative-regions/preview-storage-classes',
            ], true));

        $this->assertNotEmpty($mutating);
        foreach ($mutating as $route) {
            $this->assertContains('region.mutable', $route->gatherMiddleware(), "{$route->uri()} lacks region.mutable");
        }

        $download = Route::getRoutes()->match(Request::create('/api/knative-regions/x/kubeconfig'));
        $this->assertContains('region.mutable', $download->gatherMiddleware());
    }
}
