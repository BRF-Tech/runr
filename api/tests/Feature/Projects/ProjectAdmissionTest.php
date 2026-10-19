<?php

namespace Tests\Feature\Projects;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Rules\WithinResourceLimit;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\ImportException;
use App\Services\GitService;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Deploy guards and config limiters that modules register with ProjectAdmission (the Billing
 * module: fixed-price regions, docs/billing.md) are honoured by the deploy endpoint (with the
 * deploy's overrides), the git deploy and project updates — also in regions without a capacity
 * limit and for existing projects that do not grow.
 */
class ProjectAdmissionTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private Project $project;

    private Version $version;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class]);

        $this->userWithRole('owner');
        $this->project = $this->caasProject('orders');
        $this->version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
    }

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        parent::tearDown();
    }

    private function refuseDeploys(): void
    {
        ProjectAdmission::guardDeploys(function (Project $project) {
            throw new HttpResponseException(response()->json(['message' => "Request resources for {$project->name} first.", 'code' => 'fixed_allocation_required'], 422));
        });
    }

    public function test_a_guard_refuses_the_deploy_endpoint(): void
    {
        $this->refuseDeploys();
        $developer = $this->userWithRole('developer');

        $this->signedIn($developer)->postJson("/api/projects/{$this->project->id}/deploy", ['version_id' => $this->version->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'fixed_allocation_required');
        $this->assertSame(0, $this->project->deployments()->count());

        ProjectAdmission::reset();
        $this->signedIn($developer)->postJson("/api/projects/{$this->project->id}/deploy", ['version_id' => $this->version->id])
            ->assertStatus(202);
    }

    public function test_a_guard_refuses_the_git_deploy_with_its_message(): void
    {
        $this->refuseDeploys();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Request resources for orders first.');

        app(DeploymentStarter::class)->start($this->project, $this->version, 'git');
    }

    public function test_a_config_limiter_sees_a_project_update_as_a_whole(): void
    {
        // A region without capacity limit: the capacity check alone admits every size
        $region = KnativeRegion::create(['name' => 'fixed', 'project_namespace' => 'fixed', 'is_active' => true, 'is_demo' => true]);
        $this->project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $developer = $this->userWithRole('developer');
        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('writeFiles')->andReturn('abc123'));

        $seen = [];
        ProjectAdmission::limitConfigs(function (Project $project, ?string $regionId, array $config) use (&$seen) {
            $seen[] = [$project->id, $regionId, $config];

            return (int) ($config['max_scale'] ?? 1) > 2 ? 'At most two pods fit the approved resources.' : null;
        });

        $this->signedIn($developer)->putJson("/api/projects/{$this->project->id}", ['config' => ['max_scale' => 3]])
            ->assertStatus(422)->assertJsonValidationErrors(['config' => 'At most two pods'])
            ->assertJsonPath('code', 'resource_limit_exceeded')
            ->assertJsonPath('message', 'At most two pods fit the approved resources.');
        $this->assertSame([$this->project->id, $region->id, ['max_scale' => 3]], $seen[0]);

        $this->signedIn($developer)->putJson("/api/projects/{$this->project->id}", ['config' => ['max_scale' => 2, 'cpu' => '500m']])->assertOk();

        // A change that does not size the workload is not asked
        $this->signedIn($developer)->putJson("/api/projects/{$this->project->id}", ['description' => 'orders API'])->assertOk();
        $this->assertCount(2, $seen);

        // The capacity rule no longer asks the module (it checks the region capacity only)
        $this->assertTrue(Validator::make(['cpu' => '8'], ['cpu' => [new WithinResourceLimit('cpu', $this->project->id, $region->id)]])->passes());
    }

    public function test_a_guard_gets_the_overrides_of_the_deploy(): void
    {
        $seen = null;
        ProjectAdmission::guardDeploys(function (Project $project, DeployIntent $intent) use (&$seen) {
            $seen = $intent;
        });
        $developer = $this->userWithRole('developer');

        $this->signedIn($developer)->postJson("/api/projects/{$this->project->id}/deploy", [
            'version_id' => $this->version->id, 'deploy_config' => ['min_scale' => 3, 'scale_to_zero' => false],
        ])->assertStatus(202);

        $this->assertInstanceOf(DeployIntent::class, $seen);
        $this->assertFalse($seen->isPreview());
        $this->assertSame(3, $seen->overrides['min_scale']);
    }
}
