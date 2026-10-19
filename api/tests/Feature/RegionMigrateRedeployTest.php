<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\RegionMigrateService;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A region migration deploys onto the new cluster what ran on the old one: the version and
 * overrides of each project's live deployment, through ProjectAdmission. Stopped projects move
 * with the region but stay stopped; projects that run nothing are not deployed.
 */
class RegionMigrateRedeployTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class]);
        $this->region = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true, 'is_demo' => true]);
    }

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        parent::tearDown();
    }

    /** @return array{Project, Version, Version} */
    private function project(string $name): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'trigger_type' => 'http']);
        $v1 = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(), $v1, $v2];
    }

    public function test_the_live_version_is_redeployed_with_its_overrides(): void
    {
        // v2 exists, but v1 runs (a rollback) with two pods
        [$project, $v1] = $this->project('orders');
        $live = $project->deployments()->create(['version_id' => $v1->id, 'status' => 'active', 'deploy_config' => ['min_scale' => 2]]);

        app(RegionMigrateService::class)->redeployProjects($this->region);

        $this->assertSame('shut_down', $live->fresh()->status);
        $new = $project->deployments()->whereKeyNot($live->id)->sole();
        $this->assertSame($v1->id, $new->version_id);
        $this->assertSame(['min_scale' => 2], $new->deploy_config);
        $this->assertSame('active', $new->status);
        $this->assertSame(2, $new->sizing['min_scale'] ?? null, 'the size it was applied with is recorded');
    }

    public function test_stopped_and_idle_projects_are_not_deployed(): void
    {
        [$stopped, $v1] = $this->project('stopped');
        $stopped->deployments()->create(['version_id' => $v1->id, 'status' => 'shut_down']);
        $stopped->forceFill(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill', 'stopped_version_id' => $v1->id])->saveQuietly();
        [$idle] = $this->project('never-deployed');

        app(RegionMigrateService::class)->redeployProjects($this->region);

        $this->assertTrue($stopped->fresh()->isStopped());
        $this->assertSame(0, $stopped->deployments()->where('status', '!=', 'shut_down')->count());
        $this->assertSame(0, $idle->deployments()->count());
    }

    public function test_a_guard_that_refuses_keeps_the_project_from_being_redeployed_and_ends_its_old_deployments(): void
    {
        [$project, $v1] = $this->project('fixed');
        $live = $project->deployments()->create(['version_id' => $v1->id, 'status' => 'active']);
        ProjectAdmission::guardDeploys(fn () => throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422)));

        Log::spy();
        app(RegionMigrateService::class)->redeployProjects($this->region);

        $this->assertSame(1, $project->deployments()->count(), 'nothing is deployed');
        // Nothing of it runs on the new cluster: not left live (metered, counted as running)
        $old = $live->fresh();
        $this->assertSame('shut_down', $old->status);
        $this->assertNotNull($old->ended_at);
        $this->assertStringContainsString('Over the allocation.', (string) $old->knative_message);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'fixed not redeployed')
            && str_contains((string) ($context['reason'] ?? ''), 'Over the allocation.'));
    }
}
