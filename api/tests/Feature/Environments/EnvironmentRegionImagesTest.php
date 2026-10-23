<?php

namespace Tests\Feature\Environments;

use App\Events\DeploymentCreated;
use App\Jobs\Environments\CheckRegionImageBuildJob;
use App\Jobs\Environments\ResumeEnvironmentDeployJob;
use App\Models\Deployment;
use App\Models\EnvironmentPromotion;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Models\VersionRegionImage;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentManager;
use App\Services\Environments\Promotions;
use App\Services\Environments\RegionImages;
use App\Services\FunctionBuildService;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitService;
use App\Services\KnativeDeployService;
use App\Services\RegistryImageLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EnvironmentFixtures;
use Tests\TestCase;

/**
 * An environment in another region than its project runs a built version from its region's
 * registry (docs/environments.md#another-region): the version is built there from its code
 * (FunctionBuildService::submitReplicaBuild, the build global projects run in their other
 * regions), the deployment waits for it — pending, the environment's running deployment keeps
 * serving — and goes on when the build is ready, or fails with the build's error. The image is
 * kept per version, region and registry: a later deploy or promotion of the version into that
 * region runs it at once. Builds nothing runs any more leave the registry.
 */
class EnvironmentRegionImagesTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use RefreshDatabase;

    private const EU_ZOT = '10.96.1.1:5000';

    private const US_ZOT = '10.96.3.3:5000';

    private KnativeRegion $eu;

    private KnativeRegion $us;

    /** "<state>" of the build Job the fake cluster reports: running, succeeded, failed, missing */
    private string $job = 'running';

    protected function setUp(): void
    {
        parent::setUp();
        $this->eu = $this->region('eu', true, ['registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => self::EU_ZOT, 'knative_settings' => ['knative_domain' => 'eu.runr.test']]);
        $this->us = $this->region('us', true, ['registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => self::US_ZOT, 'knative_settings' => ['knative_domain' => 'us.runr.test']]);
        $this->fakeClusterWithJobs();
        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('getAllFilesAtVersion')->andReturn(['main.py' => 'def handler(event, context):\n    return "hi"\n']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_deploy_into_another_region_builds_the_version_there_and_keeps_the_running_deployment(): void
    {
        Event::fake([DeploymentCreated::class]);
        Bus::fake([CheckRegionImageBuildJob::class]);
        [$project, $staging, $v1, $v2] = $this->builtProjectWithStaging();
        $this->regionImage($v1, VersionRegionImage::READY, self::US_ZOT.'/runr-builds/shop:v1');
        $running = $this->liveDeployment($project, $v1, 'active', $staging);
        $builder = $this->mock(FunctionBuildService::class);
        $builder->shouldReceive('submitReplicaBuild')->once()
            ->withArgs(fn (Version $version, KnativeRegion $region, ?string $job) => $version->is($v2) && $region->is($this->us) && $job === 'rbuild-shop-v2')
            ->andReturn(self::US_ZOT.'/runr-builds/shop:v2');

        // Deployed like any version: no refusal for an image of another registry
        $deployment = app(DeploymentStarter::class)->start($project, $v2, 'dev', environment: $staging);
        $this->assertSame('pending', $deployment->status);
        $this->assertSame('active', $running->fresh()->status, 'the running deployment keeps serving while the region builds v2');

        app(KnativeDeployService::class)->deploy($project->fresh(), $v2, $deployment);

        $deployment->refresh();
        $this->assertSame('pending', $deployment->status);
        $this->assertSame(RegionImages::waitingMessage($v2, $this->us), $deployment->knative_message);
        $this->assertSame([], array_keys($this->applied()), 'nothing is applied before the image is there');
        $row = VersionRegionImage::query()->where('version_id', $v2->id)->sole();
        $this->assertSame([VersionRegionImage::BUILDING, $this->us->id, self::US_ZOT, self::US_ZOT.'/runr-builds/shop:v2', 'rbuild-shop-v2'],
            [$row->status, $row->knative_region_id, $row->registry, $row->image, $row->job_name]);
        Bus::assertDispatched(CheckRegionImageBuildJob::class, fn (CheckRegionImageBuildJob $job) => $job->regionImageId === $row->id);

        // A second deploy of the version while it builds waits for the same build
        $again = app(RegionImages::class)->prepare(DeployTarget::environment($project, $staging, $v2), $v2, $deployment);
        $this->assertSame('waiting', $again[0]);
    }

    public function test_the_ready_build_resumes_the_deploy_which_runs_the_region_s_image(): void
    {
        Event::fake([DeploymentCreated::class]);
        [$project, $staging, , $v2] = $this->builtProjectWithStaging();
        $this->mock(FunctionBuildService::class)->shouldReceive('submitReplicaBuild')->once()->andReturn(self::US_ZOT.'/runr-builds/shop:v2');
        $deployment = app(DeploymentStarter::class)->start($project, $v2, 'dev', environment: $staging);
        Bus::fake([CheckRegionImageBuildJob::class]);
        app(KnativeDeployService::class)->deploy($project->fresh(), $v2, $deployment);
        $row = VersionRegionImage::query()->sole();

        // Still building: checked again
        $this->assertSame(RegionImages::POLL_SECONDS, app(RegionImages::class)->check($row->id, (string) $row->token));

        // Done: the deploy goes on (ResumeEnvironmentDeployJob) and applies the region's image
        $this->job = 'succeeded';
        Bus::fake([ResumeEnvironmentDeployJob::class]);
        $this->assertNull(app(RegionImages::class)->check($row->id, (string) $row->fresh()->token));
        $this->assertSame(VersionRegionImage::READY, $row->fresh()->status);
        Bus::assertDispatched(ResumeEnvironmentDeployJob::class, fn (ResumeEnvironmentDeployJob $job) => $job->deploymentId === $deployment->id);

        (new ResumeEnvironmentDeployJob($deployment->id))->handle(app(KnativeDeployService::class));

        $service = $this->applied()['Service/shop-staging'] ?? null;
        $this->assertNotNull($service, 'the environment\'s Knative Service is applied in its region');
        $this->assertSame(self::US_ZOT.'/runr-builds/shop:v2', $service['spec']['template']['spec']['containers'][0]['image']);
        $this->assertSame('us.test', $this->lastApplyHost('shop-staging'), 'in the region of the environment');
        $this->assertSame(self::EU_ZOT.'/runr-builds/shop:v2', $v2->fresh()->build_image, 'the version keeps its own image');

        // A later deploy of the version into the region runs it at once
        $later = app(RegionImages::class)->prepare(DeployTarget::environment($project, $staging, $v2), $v2->fresh(), $deployment);
        $this->assertSame(['ready', self::US_ZOT.'/runr-builds/shop:v2'], $later);
    }

    public function test_a_failed_build_fails_the_deployment_and_the_promotion_that_waited_for_it(): void
    {
        Event::fake([DeploymentCreated::class]);
        Bus::fake([CheckRegionImageBuildJob::class]);
        [$project, $staging, $v1] = $this->builtProjectWithStaging();
        $dev = app(EnvironmentManager::class)->create($project, ['name' => 'dev']);
        $this->liveDeployment($project, $v1, 'active', $dev);
        $this->mock(FunctionBuildService::class)->shouldReceive('submitReplicaBuild')->once()->andReturn(self::US_ZOT.'/runr-builds/shop:v1');

        // dev (eu) → staging (us): promoted, not refused for its image
        $promotion = app(Promotions::class)->request($project, $dev, $staging, $this->userWithRole('owner'));
        $this->assertSame(EnvironmentPromotion::DEPLOYING, $promotion->status);
        $deployment = Deployment::query()->findOrFail($promotion->deployment_id);
        app(KnativeDeployService::class)->deploy($project->fresh(), $v1, $deployment);
        $row = VersionRegionImage::query()->sole();

        $this->job = 'failed';
        $this->assertNull(app(RegionImages::class)->check($row->id, (string) $row->token));

        $this->assertSame(VersionRegionImage::FAILED, $row->fresh()->status);
        $deployment->refresh();
        $this->assertSame('failed', $deployment->status);
        $this->assertStringStartsWith('The build of v1 into the registry of us failed: ', (string) $deployment->knative_message);
        $this->assertSame(EnvironmentPromotion::FAILED, $promotion->fresh()->status, 'the promotion follows its deployment');

        // A newer deploy of the version tries the build again
        Carbon::setTestNow(now()->addMinute());
        $this->mock(FunctionBuildService::class)->shouldReceive('submitReplicaBuild')->once()->andReturn(self::US_ZOT.'/runr-builds/shop:v1');
        $retry = app(DeploymentStarter::class)->start($project, $v1, 'dev', environment: $staging);
        app(KnativeDeployService::class)->deploy($project->fresh(), $v1, $retry);
        $this->assertSame([VersionRegionImage::BUILDING, 'pending'], [$row->fresh()->status, $retry->fresh()->status]);
    }

    public function test_a_version_built_again_into_its_region_s_registry_is_waited_for(): void
    {
        Event::fake([DeploymentCreated::class]);
        [$project, , $v1] = $this->builtProjectWithStaging();
        // An environment in the project's region, whose registry moved to another address
        $qa = app(EnvironmentManager::class)->create($project, ['name' => 'qa']);
        $this->eu->forceFill(['registry_address' => '10.96.9.9:5000'])->save();
        $project = $project->fresh();
        $deployment = app(DeploymentStarter::class)->start($project, $v1, 'dev', environment: $qa);
        // The project's own deploy builds v1 again into that registry meanwhile (VersionRebuilds)
        $v1->forceFill(['build_status' => 'pending'])->save();
        $this->mock(FunctionBuildService::class)->shouldNotReceive('submitReplicaBuild');

        app(KnativeDeployService::class)->deploy($project, $v1->fresh(), $deployment);
        $this->assertSame('pending', $deployment->fresh()->status);
        $this->assertStringStartsWith(RegionImages::WAITING.'the build of v1', (string) $deployment->fresh()->knative_message);

        Bus::fake([ResumeEnvironmentDeployJob::class]);
        $v1->forceFill(['build_status' => 'building', 'build_image' => '10.96.9.9:5000/runr-builds/shop:v1'])->save();
        Bus::assertNotDispatched(ResumeEnvironmentDeployJob::class);
        $v1->forceFill(['build_status' => 'ready'])->save();
        Bus::assertDispatched(ResumeEnvironmentDeployJob::class, fn (ResumeEnvironmentDeployJob $job) => $job->deploymentId === $deployment->id);
        $this->assertSame(['as_is', null], app(RegionImages::class)->prepare(DeployTarget::environment($project, $qa, $v1), $v1->fresh(), $deployment));
    }

    public function test_builds_nothing_runs_leave_the_registry(): void
    {
        [$project, $staging, $v1, $v2] = $this->builtProjectWithStaging();
        $v3 = Version::create(['project_id' => $project->id, 'number' => 3, 'commit_id' => 'c3', 'created_by' => 'dev', 'build_status' => 'ready', 'build_image' => self::EU_ZOT.'/runr-builds/shop:v3']);
        $one = $this->regionImage($v1, VersionRegionImage::READY, self::US_ZOT.'/runr-builds/shop:v1');
        $two = $this->regionImage($v2, VersionRegionImage::READY, self::US_ZOT.'/runr-builds/shop:v2');
        $three = $this->regionImage($v3, VersionRegionImage::READY, self::US_ZOT.'/runr-builds/shop:v3');
        $this->liveDeployment($project, $v1, 'active', $staging);
        $deleted = [];
        $this->mock(RegistryImageLifecycleService::class)->shouldReceive('deleteImage')
            ->andReturnUsing(function (KnativeRegion $region, string $repo, string $tag) use (&$deleted) {
                $deleted[] = "{$region->name} {$repo}:{$tag}";

                return true;
            });

        // Keep the newest one and the one staging runs
        config(['registry.retain_versions' => 1]);
        app(RegionImages::class)->retain($project->id, $this->us, self::US_ZOT);
        $this->assertSame(['us runr-builds/shop:v2'], $deleted);
        $this->assertNotNull($one->fresh());
        $this->assertNull($two->fresh());
        $this->assertNotNull($three->fresh());

        // A pruned version can run nowhere: its builds go
        $v3->forceFill(['build_status' => Version::STATUS_PRUNED, 'build_image' => null])->save();
        $this->assertNull($three->fresh());

        // The environment is deleted: what nothing else runs in its region goes
        app(EnvironmentManager::class)->delete($staging->fresh());
        $this->assertNull($one->fresh());
        $this->assertContains('us runr-builds/shop:v1', $deleted);
    }

    public function test_a_deploy_into_another_region_waits_for_the_runtime_image_built_there(): void
    {
        // No build of its own: the runtime's image, which the region builds from source first
        Event::fake([DeploymentCreated::class]);
        Bus::fake([ResumeEnvironmentDeployJob::class]);
        [$project, $staging] = $this->builtProjectWithStaging();
        $plain = Version::create(['project_id' => $project->id, 'number' => 4, 'commit_id' => 'c4', 'created_by' => 'dev', 'build_status' => 'ready']);
        $images = \Mockery::mock(RegionImages::class)->makePartial();
        $this->app->instance(RegionImages::class, $images);
        $images->shouldReceive('prepare')->andReturn(['retry', RegionImages::WAITING.'the runtime image of python, which is built in us; deployed when it is ready.']);
        $deployment = app(DeploymentStarter::class)->start($project, $plain, 'dev', environment: $staging);

        app(KnativeDeployService::class)->deploy($project->fresh(), $plain, $deployment, null, 3);

        $this->assertSame('pending', $deployment->fresh()->status);
        Bus::assertDispatched(ResumeEnvironmentDeployJob::class, fn (ResumeEnvironmentDeployJob $job) => $job->deploymentId === $deployment->id && $job->attempt === 4);
    }

    /**
     * A FaaS project in eu whose versions are built into eu's registry, and its environment
     * staging in us.
     *
     * @return array{Project, ProjectEnvironment, Version, Version}
     */
    private function builtProjectWithStaging(): array
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->eu->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        $v1 = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'c1', 'created_by' => 'dev', 'build_status' => 'ready', 'build_image' => self::EU_ZOT.'/runr-builds/shop:v1']);
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'commit_id' => 'c2', 'created_by' => 'dev', 'build_status' => 'ready', 'build_image' => self::EU_ZOT.'/runr-builds/shop:v2']);
        $project = $project->fresh();
        $staging = app(EnvironmentManager::class)->create($project, ['name' => 'staging', 'knative_region_id' => $this->us->id]);

        return [$project, $staging, $v1, $v2];
    }

    private function regionImage(Version $version, string $status, ?string $image): VersionRegionImage
    {
        return VersionRegionImage::query()->create([
            'project_id' => $version->project_id, 'version_id' => $version->id, 'knative_region_id' => $this->us->id,
            'registry' => self::US_ZOT, 'status' => $status, 'image' => $image, 'job_name' => "rbuild-shop-v{$version->number}",
        ]);
    }

    /** The host the last apply of $name went to. */
    private function lastApplyHost(string $name): string
    {
        foreach (array_reverse($this->clusterCalls) as $call) {
            if ($call['method'] === 'PATCH' && ($call['body']['metadata']['name'] ?? null) === $name) {
                return (string) parse_url($call['url'], PHP_URL_HOST);
            }
        }

        return '';
    }

    /**
     * fakeCluster() with the build Job of a region build in the state $this->job, and a pod log
     * that says why it failed.
     */
    private function fakeClusterWithJobs(): void
    {
        $this->clusterCalls = [];
        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true);
            $this->clusterCalls[] = ['method' => $request->method(), 'url' => $request->url(), 'body' => is_array($body) ? $body : null];
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/jobs/rbuild-')) {
                return match ($this->job) {
                    'succeeded' => Http::response(['metadata' => ['name' => 'rbuild'], 'status' => ['succeeded' => 1]]),
                    'failed' => Http::response(['metadata' => ['name' => 'rbuild'], 'status' => ['failed' => 1, 'conditions' => [['type' => 'Failed', 'message' => 'BackoffLimitExceeded']]]]),
                    'missing' => Http::response(['kind' => 'Status', 'code' => 404], 404),
                    default => Http::response(['metadata' => ['name' => 'rbuild'], 'status' => ['active' => 1]]),
                };
            }

            return match ($request->method()) {
                'GET' => str_contains($url, '/services/')
                    ? Http::response([
                        'metadata' => ['generation' => 1],
                        'status' => ['observedGeneration' => 1, 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
                    ])
                    : Http::response(['items' => []]),
                'DELETE' => Http::response(['kind' => 'Status', 'code' => 404], 404),
                default => Http::response(is_array($body) ? $body : []),
            };
        });
    }
}
