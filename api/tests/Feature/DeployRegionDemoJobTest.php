<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Jobs\DeployRegionDemoJob;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The demo projects of a new region are deployed once their versions are built — each through the
 * deploy guards of ProjectAdmission, like any deploy (e.g. a fixed-price region without an
 * approved allocation deploys nothing).
 */
class DeployRegionDemoJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        parent::tearDown();
    }

    public function test_demo_projects_are_deployed_unless_a_guard_refuses(): void
    {
        Event::fake([DeploymentCreated::class]);
        $region = KnativeRegion::create(['name' => 'demo', 'project_namespace' => 'demo', 'is_active' => true, 'is_demo' => true]);
        $projects = collect(['hello', 'refused'])->map(function (string $name) use ($region) {
            $project = Project::create(['name' => $name, 'service_type' => 'caas']);
            $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
            ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'trigger_type' => 'http']);
            Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'system', 'build_status' => 'ready']);

            return $project;
        });
        ProjectAdmission::guardDeploys(function (Project $project) {
            if ($project->name === 'refused') {
                throw new HttpResponseException(response()->json(['message' => 'Request resources first.'], 422));
            }
        });

        (new DeployRegionDemoJob($region, $projects->pluck('id')->all()))->handle();

        $this->assertSame(1, $projects[0]->deployments()->count());
        $this->assertSame(0, $projects[1]->deployments()->count());
        Event::assertDispatchedTimes(DeploymentCreated::class, 1);
        $this->assertSame('ready', $region->fresh()->region_demo_status);
    }
}
