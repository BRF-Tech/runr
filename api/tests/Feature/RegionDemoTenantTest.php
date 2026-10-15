<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Tests\TestCase;

/**
 * Removing the region demo inside a tenant failed: the tenant copy of a region (TenantAwareKnativeRegion)
 * looked up its projects by tenant_aware_knative_region_id. After that failed removal a new seed created a
 * second set of demo projects with the same names.
 */
class RegionDemoTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_region_copy_finds_its_projects(): void
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn']);
        $project = Project::create(['name' => 'contact-backend', 'service_type' => 'faas', 'runtime' => 'php']);
        $project->forceFill(['knative_region_id' => $region->id, 'is_region_demo' => true])->saveQuietly();

        $copy = TenantAwareKnativeRegion::findOrFail($region->id);

        $this->assertSame('knative_region_id', $copy->projects()->getForeignKeyName());
        $this->assertSame([$project->id], $copy->projects()->where('is_region_demo', true)->pluck('id')->all());
    }

    public function test_demo_is_not_seeded_again_while_demo_projects_exist(): void
    {
        Bus::fake();
        $user = User::factory()->create(['username' => 'admin']);
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn']);
        $region->forceFill(['region_demo_status' => 'error'])->saveQuietly();
        $project = Project::create(['name' => 'contact-backend', 'service_type' => 'faas', 'runtime' => 'php']);
        $project->forceFill(['knative_region_id' => $region->id, 'is_region_demo' => true])->saveQuietly();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/knative-regions/{$region->id}/demo-projects")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.demo.already_exists'));

        Bus::assertNothingDispatched();
    }
}
