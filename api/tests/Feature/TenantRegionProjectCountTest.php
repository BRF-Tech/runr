<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * `runr regions` showed PROJECTS 0 to a tenant for a platform region the tenant runs projects in:
 * the tenant's copy of a platform region (is_synced) answered without project_count. The count
 * comes from the projects table of the request's context — the tenant's own projects for a
 * tenant user, the platform's for the platform view.
 */
class TenantRegionProjectCountTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $tenant;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        // the platform region, with one project of the platform's own
        $this->region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
        $this->project('platform-app');

        $this->tenant = $this->createTenantWithSchema('acme', [
            ...self::TENANT_REGION_MIGRATIONS,
            'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        ]);
        $this->tenant->run(function () {
            // the tenant's copy of the platform region (central → tenant sync) and three projects there
            $copy = new KnativeRegion(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
            $copy->forceFill(['id' => $this->region->id, 'is_synced' => true])->saveQuietly();
            foreach (['orders', 'billing', 'search'] as $name) {
                $this->project($name);
            }
            // a region of the tenant's own without projects
            $own = new KnativeRegion(['name' => 'acme-own', 'project_namespace' => 'acme-own', 'is_active' => true]);
            $own->forceFill(['is_synced' => false, 'owner_tenant_id' => $this->tenant->id])->saveQuietly();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function project(string $name): void
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
    }

    private function tenantOwner(): User
    {
        $user = User::factory()->create(['username' => 'u'.Str::random(8)]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_a_tenant_sees_its_own_projects_in_a_platform_region(): void
    {
        $owner = $this->tenantOwner();
        tenancy()->initialize($this->tenant);

        $regions = collect($this->actingAs($owner, 'sanctum')->getJson('/api/knative-regions')->assertOk()->json('data'))->keyBy('name');

        $this->assertTrue($regions['fn']['is_synced']);
        $this->assertSame(3, $regions['fn']['project_count'], 'the tenant\'s projects, not the platform\'s');
        $this->assertArrayNotHasKey('kubeconfig', $regions['fn']);
        $this->assertSame(0, $regions['acme-own']['project_count']);

        $this->actingAs($owner, 'sanctum')->getJson("/api/knative-regions/{$this->region->id}")
            ->assertOk()
            ->assertJsonPath('data.is_synced', true)
            ->assertJsonPath('data.project_count', 3);
    }

    public function test_the_platform_view_counts_the_platforms_projects(): void
    {
        $admin = User::factory()->create(['username' => 'u'.Str::random(8)]);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin, 'sanctum')->getJson("/api/knative-regions/{$this->region->id}")
            ->assertOk()
            ->assertJsonPath('data.is_synced', false)
            ->assertJsonPath('data.project_count', 1);
    }
}
