<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Services\RegionMigrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rows of the retired AI types (aaas, agentflow, maas) are view-and-delete only. The deployer
 * no longer marks a deployment of one "active" without deploying anything: it fails it, and a
 * region migration does not redeploy them at all.
 */
class LegacyAiTypeDeployTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = KnativeRegion::create(['name' => 'op', 'is_active' => true, 'is_demo' => true]);
    }

    private function project(string $serviceType): Project
    {
        $project = Project::create(['name' => 'legacy-'.$serviceType, 'service_type' => $serviceType]);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

        match ($serviceType) {
            'aaas' => $project->aaasConfig()->create([]),
            'agentflow' => $project->agentFlowConfig()->create([]),
            'maas' => $project->maasConfig()->create([]),
            'caas' => ContainerConfig::create([
                'project_id' => $project->id,
                'image' => 'nginx:1.27-alpine',
                'cpu' => '250m',
                'memory_mb' => 128,
                'timeout_seconds' => 30,
                'trigger_type' => 'http',
            ]),
        };

        Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return $project->fresh();
    }

    public function test_a_deployment_of_a_retired_ai_type_fails_instead_of_turning_active(): void
    {
        foreach (['aaas', 'agentflow', 'maas'] as $type) {
            $project = $this->project($type);
            $version = $project->versions()->first();
            $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'pending']);

            app(KnativeDeployService::class)->deploy($project, $version, $deployment);

            $deployment->refresh();
            $this->assertSame('failed', $deployment->status, $type);
            $this->assertSame("Projects of type {$type} can no longer be deployed", $deployment->knative_message);
        }
    }

    public function test_a_supported_type_still_deploys_on_a_demo_region(): void
    {
        $project = $this->project('caas');
        $version = $project->versions()->first();
        $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'pending']);

        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $this->assertSame('active', $deployment->fresh()->status);
    }

    public function test_a_region_migration_redeploys_only_supported_types(): void
    {
        $legacy = $this->project('aaas');
        $supported = $this->project('caas');

        app(RegionMigrateService::class)->redeployProjects($this->region);

        $this->assertSame(0, $legacy->deployments()->count());
        $this->assertSame(1, $supported->deployments()->count());
        $this->assertSame('active', $supported->deployments()->first()->status);
    }
}
