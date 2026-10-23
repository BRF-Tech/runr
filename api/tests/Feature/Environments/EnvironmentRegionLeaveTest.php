<?php

namespace Tests\Feature\Environments;

use App\Events\DeploymentCreated;
use App\Models\Deployment;
use App\Models\ProjectDomain;
use App\Models\VersionRegionImage;
use App\Services\Environments\EnvironmentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EnvironmentFixtures;
use Tests\TestCase;

/**
 * An environment leaves a region the way its project does (docs/environments.md):
 *
 *  - the organization loses the region (Tenancy: the region is detached,
 *    DetachRegionCleanupJob → EnvironmentManager::leaveRegion): every environment that runs there
 *    — following its project there, or with the region as its own — stops, its workloads leave the
 *    region, its custom domains wait for a new verification, its builds there are forgotten, and an
 *    environment with the region as its own follows its project from then on;
 *  - an environment moved to another region while nothing runs in it, or one that follows its
 *    project to another region: its custom domains leave the old region and wait for a new
 *    verification.
 */
class EnvironmentRegionLeaveTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentCreated::class]);
        $this->fakeCluster();
    }

    public function test_a_detached_region_takes_its_environments_down_with_its_projects(): void
    {
        $eu = $this->region('eu');
        $us = $this->region('us');
        $project = $this->caasProject('shop', $eu);
        $v1 = $this->caasVersion($project, 1);
        $production = $this->liveDeployment($project, $v1);
        $staging = $this->environment($project, 'staging');
        $abroad = $this->environment($project, 'abroad', ['knative_region_id' => $us->id]);
        $stagingLive = $this->liveDeployment($project, $v1, 'active', $staging);
        $abroadLive = $this->liveDeployment($project, $v1, 'active', $abroad);
        $domain = ProjectDomain::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'domain' => 'staging.example.com',
            'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 'x', 'tls_mode' => ProjectDomain::TLS_NONE]);
        $build = VersionRegionImage::query()->create(['project_id' => $project->id, 'version_id' => $v1->id, 'knative_region_id' => $eu->id,
            'registry' => '10.0.0.1:5000', 'status' => VersionRegionImage::READY, 'image' => '10.0.0.1:5000/runr-builds/shop:v1']);

        $failures = app(EnvironmentManager::class)->leaveRegion($eu);

        $this->assertSame([], $failures);
        $this->assertSame('shut_down', $stagingLive->fresh()->status);
        $this->assertContains('https://eu.test:6443/apis/serving.knative.dev/v1/namespaces/eu-fn/services/shop-staging', $this->deleted(), 'its workload leaves the region');
        $this->assertSame(ProjectDomain::STATUS_PENDING, $domain->fresh()->status, 'its custom domain waits for a new verification');
        $this->assertNull($build->fresh(), 'its builds in the region are forgotten');
        $this->assertSame('active', $abroadLive->fresh()->status, 'an environment in another region runs on');
        $this->assertSame('active', $production->fresh()->status, 'the project is the detach job\'s');

        // The project leaves the region next: staging, which runs nothing now, is not kept there
        $project->update(['knative_region_id' => null]);
        $this->assertNull($staging->fresh()->knative_region_id);
        $this->assertSame($us->id, $abroad->fresh()->knative_region_id);

        // The region an environment has as its own goes: it follows its project from then on
        app(EnvironmentManager::class)->leaveRegion($us);
        $this->assertSame('shut_down', $abroadLive->fresh()->status);
        $this->assertNull($abroad->fresh()->knative_region_id);
        $this->assertSame(0, Deployment::query()->whereNotNull('environment_id')->whereIn('status', Deployment::LIVE)->count());
    }

    public function test_the_custom_domains_of_an_environment_follow_it_to_another_region(): void
    {
        $eu = $this->region('eu');
        $us = $this->region('us');
        $project = $this->caasProject('shop', $eu);
        $staging = $this->environment($project, 'staging');
        $domain = ProjectDomain::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'domain' => 'staging.example.com',
            'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 'x', 'tls_mode' => ProjectDomain::TLS_NONE]);

        $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->patchJson("/api/projects/{$project->id}/environments/staging", ['knative_region_id' => $us->id])
            ->assertOk();

        $this->assertSame(ProjectDomain::STATUS_PENDING, $domain->fresh()->status);
        $this->assertNotEmpty(array_filter($this->deleted(), fn (string $url) => str_starts_with($url, 'https://eu.test:6443/apis/traefik.io/')),
            'its routes leave the old region');

        // Following the project: it moves with the project when nothing runs in it
        $follower = $this->environment($project, 'qa');
        $qaDomain = ProjectDomain::query()->create(['project_id' => $project->id, 'environment_id' => $follower->id, 'domain' => 'qa.example.com',
            'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 'y', 'tls_mode' => ProjectDomain::TLS_NONE]);
        $project->update(['knative_region_id' => $us->id]);
        $this->assertNull($follower->fresh()->knative_region_id, 'it follows the project');
        $this->assertSame(ProjectDomain::STATUS_PENDING, $qaDomain->fresh()->status);
    }
}
