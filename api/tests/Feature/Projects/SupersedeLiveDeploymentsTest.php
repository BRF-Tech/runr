<?php

namespace Tests\Feature\Projects;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\CheckDeploymentReadyJob;
use App\Jobs\RuntimeTestDeployJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\Git\GitDeploy;
use App\Models\Git\PreviewEnvironment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\KnativeDeployService;
use App\Services\Projects\LiveDeployments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * A deploy replaces every live deployment of the project (pending, running, active), on every
 * deploy path: a pending deployment whose readiness check polls the project's shared Knative
 * Service would otherwise go live next to the new one, and both would be metered. The readiness
 * check of a replaced deployment ends without activating it; previews keep their own lifecycle.
 */
class SupersedeLiveDeploymentsTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The Knative deploy itself is not under test
        Event::fake([DeploymentCreated::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_redeploy_replaces_a_deployment_that_is_still_pending(): void
    {
        $project = $this->project('starter');
        $version = $this->version($project);

        Carbon::setTestNow('2026-10-11 06:00:00');
        $first = app(DeploymentStarter::class)->start($project, $version, 'dev');
        Carbon::setTestNow('2026-10-11 06:01:00');
        $second = app(DeploymentStarter::class)->start($project->fresh(), $version, 'dev');

        $first->refresh();
        $this->assertFalse($first->isLive(), 'the pending deployment was replaced, not left to go live too');
        $this->assertSame('2026-10-11 06:01:00', $first->ended_at?->toDateTimeString());
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame([$second->id], Deployment::query()->whereIn('status', Deployment::LIVE)->pluck('id')->all());
    }

    public function test_the_deploy_endpoint_replaces_every_live_deployment_and_leaves_previews_alone(): void
    {
        $project = $this->project('endpoint');
        $version = $this->version($project);
        $active = $this->deployment($project, $version, 'active');
        $pending = $this->deployment($project, $version, 'pending');
        $preview = PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 7, 'provider' => 'github', 'status' => PreviewEnvironment::READY, 'service_name' => 'endpoint-pr-7']);

        $this->actingAs($this->userWithRole('owner'), 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $version->id])
            ->assertAccepted();

        $this->assertFalse($active->fresh()->isLive());
        $this->assertFalse($pending->fresh()->isLive());
        $this->assertSame(1, Deployment::query()->whereIn('status', Deployment::LIVE)->count());
        $this->assertSame(PreviewEnvironment::READY, $preview->fresh()->status, 'a production deploy does not touch previews');
    }

    public function test_the_readiness_check_of_a_replaced_deployment_does_not_activate_it(): void
    {
        $project = $this->project('ready', withCluster: true);
        $version = $this->version($project);
        $this->readyService();

        // Replaced while it started (the redeploy set it shutting_down): stays out of service
        $replaced = $this->deployment($project, $version, 'shutting_down');
        (new CheckDeploymentReadyJob($replaced, $project))->handle();
        $this->assertSame('shutting_down', $replaced->fresh()->status);

        // Two deploys at once left two pending deployments: the older one ends, the newer goes live
        Carbon::setTestNow('2026-10-11 06:00:00');
        $older = $this->deployment($project, $version, 'pending');
        Carbon::setTestNow('2026-10-11 06:00:01');
        $newer = $this->deployment($project, $version, 'pending');

        (new CheckDeploymentReadyJob($older, $project))->handle();
        (new CheckDeploymentReadyJob($newer, $project))->handle();

        $this->assertFalse($older->fresh()->isLive(), 'the older one is not activated next to the newer one');
        $this->assertNull($older->fresh()->ready_at);
        $this->assertSame('active', $newer->fresh()->status);
    }

    public function test_two_deploys_created_at_the_same_instant_never_both_stay_active(): void
    {
        $project = $this->project('race', withCluster: true);
        $version = $this->version($project);
        $this->readyService();

        // The race: the newer deploy superseded before the older deployment existed, and the older
        // one went live before the newer one was created — both at 06:00:00
        Carbon::setTestNow('2026-10-11 06:00:00');
        $older = $this->deployment($project, $version, 'pending');
        (new CheckDeploymentReadyJob($older, $project))->handle();
        $this->assertSame('active', $older->fresh()->status, 'no newer deployment existed yet');

        $newer = $this->deployment($project, $version, 'pending');
        $this->assertEquals($older->created_at, $newer->created_at, 'created at the same instant');
        (new CheckDeploymentReadyJob($newer, $project))->handle();

        $this->assertSame('active', $newer->fresh()->status);
        $this->assertFalse($older->fresh()->isLive(), 'the activation replaced the older one (and its teardown ran)');
        $this->assertSame('2026-10-11 06:00:00', $older->fresh()->ended_at?->toDateTimeString());
        $this->assertSame([$newer->id], Deployment::query()->where('status', 'active')->pluck('id')->all(), 'at most one active');

        // A readiness check of the older one that comes late changes nothing
        (new CheckDeploymentReadyJob($older->fresh(), $project))->handle();
        $this->assertSame([$newer->id], Deployment::query()->where('status', 'active')->pluck('id')->all());
    }

    public function test_an_older_deployment_ready_after_a_newer_one_was_created_is_not_activated(): void
    {
        $project = $this->project('order', withCluster: true);
        $version = $this->version($project);
        $this->readyService();

        Carbon::setTestNow('2026-10-11 06:00:00');
        $older = $this->deployment($project, $version, 'pending');
        $newer = $this->deployment($project, $version, 'pending');

        $this->assertFalse(app(LiveDeployments::class)->activate($older->fresh(), ['ready_at' => now()]));
        $this->assertFalse($older->fresh()->isLive());
        $this->assertNull($older->fresh()->ready_at, 'never went live');

        $this->assertTrue(app(LiveDeployments::class)->activate($newer->fresh(), ['ready_at' => now()]));
        $this->assertSame('active', $newer->fresh()->status);
    }

    public function test_activating_queues_the_teardown_of_the_replaced_deployment_after_the_commit(): void
    {
        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);
        $project = $this->project('teardown');
        $version = $this->version($project);

        Carbon::setTestNow('2026-10-11 06:00:00');
        $older = $this->deployment($project, $version, 'active');
        $newer = $this->deployment($project, $version, 'pending');

        $this->assertTrue(app(LiveDeployments::class)->activate($newer, ['ready_at' => now()]));

        Event::assertDispatched(DeploymentStatusChanged::class, fn (DeploymentStatusChanged $e) => $e->deployment->id === $older->id
            && $e->previousStatus === 'active' && $e->newStatus === 'shutting_down');
        Event::assertDispatchedTimes(DeploymentStatusChanged::class, 1);
    }

    public function test_a_deployment_activated_without_a_readiness_check_replaces_the_older_one(): void
    {
        // A demo region (and a CronJob) goes active right after the apply, through the same activation
        $region = KnativeRegion::create(['name' => 'demo-fn', 'is_active' => true, 'is_demo' => true]);
        $project = Project::create(['name' => 'demo', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 1]);
        $version = $this->version($project);

        Carbon::setTestNow('2026-10-11 06:00:00');
        $older = $this->deployment($project, $version, 'active');
        $newer = $this->deployment($project, $version, 'pending');
        app(KnativeDeployService::class)->deploy($project->fresh(), $version, $newer);

        $this->assertSame('active', $newer->fresh()->status);
        $this->assertFalse($older->fresh()->isLive());
    }

    public function test_a_replaced_git_deploy_ends_instead_of_deploying_forever(): void
    {
        $project = $this->project('git');
        $version = $this->version($project);
        $pending = $this->deployment($project, $version, 'pending');
        $deploy = GitDeploy::create([
            'project_id' => $project->id, 'kind' => GitDeploy::KIND_PRODUCTION, 'trigger' => 'push', 'provider' => 'runr',
            'sha' => str_repeat('a', 40), 'branch' => 'main', 'author_name' => 'Ada', 'status' => GitDeploy::DEPLOYING,
            'deployment_id' => $pending->id,
        ]);

        app(DeploymentStarter::class)->start($project, $version, 'dev');

        $this->assertSame(GitDeploy::SKIPPED, $deploy->fresh()->status);
        $this->assertContains($deploy->fresh()->status, GitDeploy::FINAL);
    }

    public function test_a_runtime_test_deploy_replaces_what_runs(): void
    {
        $project = $this->project('runtime');
        $version = $this->version($project);
        $active = $this->deployment($project, $version, 'active');

        Bus::dispatchSync(new RuntimeTestDeployJob($version, 'dev'));

        $this->assertFalse($active->fresh()->isLive());
        $this->assertSame(1, Deployment::query()->whereIn('status', Deployment::LIVE)->count());
    }

    private function project(string $name, bool $withCluster = false): Project
    {
        $region = KnativeRegion::create(array_filter([
            'name' => $name.'-fn', 'project_namespace' => 'fn', 'is_active' => true,
            'kubeconfig' => $withCluster ? Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2) : null,
        ]));
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 1]);

        return $project->fresh();
    }

    private function version(Project $project): Version
    {
        return Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
    }

    private function deployment(Project $project, Version $version, string $status): Deployment
    {
        return $project->deployments()->create(['version_id' => $version->id, 'status' => $status, 'k8s_job_name' => "run-{$project->slug}-v1", 'deployed_by' => 'dev']);
    }

    /** The project's Knative Service answers Ready on the current revision; deletes find nothing left. */
    private function readyService(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response([
                'metadata' => ['generation' => 1],
                'status' => ['observedGeneration' => 1, 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
            ])
            : Http::response(['kind' => 'Status', 'code' => 404], 404));
    }
}
