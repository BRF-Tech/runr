<?php

namespace Tests\Feature\Projects;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\ProcessQueuedDeployJob;
use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\ImageSecurityReport;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\Version;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\Projects\QueuedDeploys;
use App\Services\RegionMigrateService;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\SupplyChain\DeployPolicyGate;
use App\Services\SupplyChain\PolicyDecision;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Deploy after build (QueuedDeploys): a deploy queued for a version goes through the deploy
 * checks of every deploy once the version's build ends — and waits for the version's
 * supply-chain checks and for a runtime image RunR builds in the region — or ends with the
 * reason. A region migration's queued deploy shuts the live deployment it replaces down when it
 * cannot happen — and so does a newer queued deploy that took its place while it waited.
 */
class QueuedDeploysTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class]);
        $this->region = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true, 'is_demo' => true]);
    }

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        parent::tearDown();
    }

    /** @return array{Project, Version, Deployment} a FaaS project running v1, its v1 building again */
    private function project(string $name = 'orders'): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev',
            'build_status' => 'ready', 'build_image' => "10.96.1.1:5000/runr-builds/{$name}:v1"]);
        $live = $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev',
            'deploy_config' => ['min_scale' => 2]]);
        $version->forceFill(['build_status' => 'pending'])->save();

        return [$project->fresh(), $version, $live];
    }

    private function queue(Project $project, Version $version, Deployment $live, ?string $reason = QueuedDeploys::REASON_REGION_MIGRATION): QueuedDeploy
    {
        return app(QueuedDeploys::class)->queue($project, $version, 'region-migration', [
            'deploy_config' => $live->deploy_config,
            'reason' => $reason,
            'replaces' => $live,
            'previous_build_image' => $version->build_image,
        ]);
    }

    private function built(Version $version, string $status = 'ready', ?string $error = null): void
    {
        $version->forceFill(['build_status' => 'building', 'build_image' => '10.96.2.2:5000/runr-builds/orders:v1'])->save();
        $version->forceFill(['build_status' => $status, 'build_error' => $error])->save();
    }

    public function test_the_deploy_starts_through_the_deploy_checks_once_the_version_is_built(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live);
        $this->assertSame(QueuedDeploy::WAITING, $queued->status);
        $this->assertSame('Waits for the build of v1.', $queued->message);
        $this->assertSame('active', $live->fresh()->status, 'the live deployment runs while the version builds');

        $this->built($version);

        $queued->refresh();
        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->status);
        $this->assertSame('v1 was built and its deploy started.', $queued->message);
        $deployment = Deployment::query()->findOrFail($queued->deployment_id);
        $this->assertSame('active', $deployment->status);
        $this->assertSame('region-migration', $deployment->deployed_by);
        $this->assertSame(['min_scale' => 2], $deployment->deploy_config, 'with the overrides it was queued with');
        $this->assertSame('shutting_down', $live->fresh()->status, 'replaced like by any deploy');
    }

    public function test_a_refused_deploy_ends_it_and_the_migration_shuts_the_replaced_deployment_down(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live);
        ProjectAdmission::guardDeploys(fn () => throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422)));

        $this->built($version);

        $queued->refresh();
        $this->assertSame(QueuedDeploy::FAILED, $queued->status);
        $this->assertSame('Over the allocation.', $queued->message);
        $this->assertNull($queued->deployment_id);
        $live->refresh();
        $this->assertSame('shut_down', $live->status);
        $this->assertSame(RegionMigrateService::NOT_MOVED.'Over the allocation.', $live->knative_message);
        $this->assertNotNull($live->ended_at, 'not metered from then on');
    }

    public function test_a_failed_build_ends_it_with_the_build_error(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live);

        $this->built($version, 'failed', 'pip: no matching distribution found for left-pad==9');

        $this->assertSame(QueuedDeploy::FAILED, $queued->fresh()->status);
        $this->assertSame('V1 could not be built: pip: no matching distribution found for left-pad==9', $queued->fresh()->message);
        $this->assertSame(RegionMigrateService::NOT_MOVED.'v1 could not be built: pip: no matching distribution found for left-pad==9', $live->fresh()->knative_message);
    }

    public function test_without_a_reason_that_ends_it_the_replaced_deployment_keeps_running(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live, reason: null);

        $this->built($version, 'failed', 'compile error');

        $this->assertSame(QueuedDeploy::FAILED, $queued->fresh()->status);
        $this->assertSame('active', $live->fresh()->status);
    }

    public function test_a_cancelled_build_cancels_it_without_a_failure(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live);

        $version->forceFill(['build_status' => 'building'])->save();
        $version->forceFill(['build_status' => Version::STATUS_CANCELLED, 'build_error' => 'Cancelled by the migration rollback.'])->save();

        $this->assertSame(QueuedDeploy::CANCELLED, $queued->fresh()->status);
        $this->assertSame('The build of v1 was cancelled: Cancelled by the migration rollback.', $queued->fresh()->message);
        $this->assertSame('active', $live->fresh()->status, 'nothing failed: the live deployment stays');
    }

    public function test_a_pruned_version_and_a_deleted_one_end_it(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live, reason: null);
        $version->forceFill(['build_status' => Version::STATUS_PRUNED])->save();
        $this->assertSame('The build image of v1 was removed by registry retention', $queued->fresh()->message);

        $other = QueuedDeploy::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'deployed_by' => 'dev']);
        $version->delete();
        app(QueuedDeploys::class)->process($other);
        $this->assertSame(0, QueuedDeploy::query()->count(), 'gone with its version');
    }

    public function test_a_newer_deploy_of_the_project_replaces_it(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live);
        $this->travel(1)->seconds();
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project->deployments()->create(['version_id' => $v2->id, 'status' => 'active', 'deployed_by' => 'dev']);

        $this->built($version);

        $this->assertSame(QueuedDeploy::CANCELLED, $queued->fresh()->status);
        $this->assertSame('Replaced by a newer deploy of the project.', $queued->fresh()->message);
        $this->assertSame(0, $project->deployments()->where('version_id', $version->id)->whereKeyNot($live->id)->count());
    }

    public function test_a_newer_queued_deploy_replaces_a_waiting_one(): void
    {
        [$project, $version, $live] = $this->project();
        $first = $this->queue($project, $version, $live);
        $second = $this->queue($project, $version, $live);

        $this->assertSame(QueuedDeploy::CANCELLED, $first->fresh()->status);
        $this->assertSame(QueuedDeploy::WAITING, $second->fresh()->status);
    }

    public function test_a_deploy_that_replaces_a_waiting_migration_deploy_takes_its_place(): void
    {
        [$project, $version, $live] = $this->project();
        $move = $this->queue($project, $version, $live);
        $older = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'pending']);

        $first = app(QueuedDeploys::class)->queue($project, $older, 'dev', ['reason' => QueuedDeploys::REASON_REBUILD]);
        // Replaced in turn: the place goes on to the newer one
        $second = app(QueuedDeploys::class)->queue($project, $older, 'dev', ['reason' => QueuedDeploys::REASON_REBUILD]);

        $this->assertSame([QueuedDeploy::CANCELLED, QueuedDeploy::CANCELLED], [$move->fresh()->status, $first->fresh()->status]);
        foreach ([$first, $second] as $queued) {
            $this->assertSame([QueuedDeploys::REASON_REBUILD, QueuedDeploys::REASON_REGION_MIGRATION, $live->id],
                [$queued->reason, $queued->replaces_reason, $queued->replaces_deployment_id]);
        }

        $this->built($older, 'failed', 'compile error');

        $this->assertSame(QueuedDeploy::FAILED, $second->fresh()->status);
        $live->refresh();
        $this->assertSame(['shut_down', RegionMigrateService::NOT_MOVED.'v2 could not be built: compile error'], [$live->status, $live->knative_message],
            'as the migration\'s own deploy would have');
    }

    public function test_a_deploy_queued_once_the_migration_deploys_deployment_was_replaced_takes_no_place(): void
    {
        [$project, $version, $live] = $this->project();
        $this->queue($project, $version, $live);
        $other = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'pending']);
        // Replaced by a deploy that did not wait for a build
        $live->transition(Deployment::LIVE, ['status' => 'shutting_down']);
        $newer = $project->deployments()->create(['version_id' => $other->id, 'status' => 'active', 'deployed_by' => 'dev']);
        $this->travel(1)->seconds();

        $queued = app(QueuedDeploys::class)->queue($project, $other, 'dev', ['reason' => QueuedDeploys::REASON_REBUILD]);
        $this->assertSame([null, null], [$queued->replaces_reason, $queued->replaces_deployment_id]);

        $this->built($other, 'failed', 'compile error');

        $this->assertSame(QueuedDeploy::FAILED, $queued->fresh()->status);
        $this->assertSame('active', $newer->fresh()->status, 'what runs now keeps running');
        $this->assertNull($live->fresh()->knative_message);
    }

    public function test_it_waits_for_the_supply_chain_checks_and_goes_on_when_they_are_final(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live);
        $this->gate([['rule' => 'scan_pending', 'message' => 'the vulnerability scan is running', 'enforced' => true]]);

        $this->built($version);

        $queued->refresh();
        $this->assertSame(QueuedDeploy::WAITING, $queued->status);
        $this->assertStringContainsString('the vulnerability scan is running', (string) $queued->message);
        $this->assertSame('active', $live->fresh()->status);

        // The report of the version is final and the gate passes
        $this->gate([]);
        $report = ImageSecurityReport::create([
            'subject_type' => ImageSecurityReport::SUBJECT_VERSION, 'subject_id' => $version->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'knative_region_id' => $this->region->id, 'image_kind' => ImageSecurityReport::KIND_BUILT,
            'image_ref' => (string) $version->fresh()->build_image, 'status' => 'running',
        ]);
        $report->update(['status' => 'completed']);

        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->fresh()->status);
    }

    public function test_one_step_starts_the_deploy_and_a_start_that_breaks_ends_it(): void
    {
        [$project, $version, $live] = $this->project();
        $queued = $this->queue($project, $version, $live, reason: null);
        $version->forceFill(['build_status' => 'ready'])->save();
        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->fresh()->status);

        // Another step for the same deploy (a second hook) finds it taken: nothing more starts
        app(QueuedDeploys::class)->process($queued);
        $this->assertSame(1, $project->deployments()->whereKeyNot($live->id)->count());

        [$other, $v, $running] = $this->project('billing');
        $broken = $this->queue($other, $v, $running, reason: null);
        $this->mock(DeploymentStarter::class, fn ($mock) => $mock->shouldReceive('start')->andThrow(new \RuntimeException('connection reset')));
        $v->forceFill(['build_status' => 'ready'])->saveQuietly();

        try {
            app(QueuedDeploys::class)->process($broken);
            $this->fail('the error is not swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('connection reset', $e->getMessage());
        }
        $this->assertSame([QueuedDeploy::FAILED, 'The deploy could not start: connection reset'], [$broken->fresh()->status, $broken->fresh()->message]);
    }

    public function test_a_version_on_a_runtime_image_still_building_in_the_region_waits_for_it(): void
    {
        [$project, $version, $live] = $this->project();
        // No build of its own: it runs on the runtime version's image, which RunR builds in the region
        $version->forceFill(['build_image' => null])->save();
        $project->knativeRegion->forceFill(['is_demo' => false, 'kubeconfig' => 'apiVersion: v1'])->save();
        $queued = $this->queue($project, $version, $live, reason: null);

        $pending = true;
        $this->runtimeImage($project, function (RuntimeManifest $manifest) use (&$pending) {
            if ($pending) {
                throw new RuntimeImagePendingException($manifest, '3.12', null);
            }

            return 'ghcr.io/brf-tech/runr/python:3.12';
        });
        Bus::fake([ProcessQueuedDeployJob::class]);

        $version->forceFill(['build_status' => 'ready'])->save();
        app(QueuedDeploys::class)->process($queued);

        $queued->refresh();
        $this->assertSame(QueuedDeploy::WAITING, $queued->status);
        $this->assertSame(1, $queued->attempts);
        $this->assertStringStartsWith('Waits for the runtime image of python', (string) $queued->message);
        Bus::assertDispatched(ProcessQueuedDeployJob::class, fn ($job) => $job->queuedDeployId === $queued->id);

        // Built: the next check deploys
        $pending = false;
        (new ProcessQueuedDeployJob($queued->id))->handle(app(QueuedDeploys::class));
        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->fresh()->status);
    }

    public function test_a_runtime_image_that_is_never_built_ends_it(): void
    {
        [$project, $version, $live] = $this->project();
        $version->forceFill(['build_image' => null, 'build_status' => 'ready'])->save();
        $project->knativeRegion->forceFill(['is_demo' => false, 'kubeconfig' => 'apiVersion: v1'])->save();
        $queued = $this->queue($project, $version, $live);
        $queued->forceFill(['attempts' => QueuedDeploys::WAIT_ATTEMPTS])->save();

        $this->runtimeImage($project, fn (RuntimeManifest $manifest) => throw new RuntimeImagePendingException($manifest, '3.12', null));

        app(QueuedDeploys::class)->process($queued);

        $this->assertSame(QueuedDeploy::FAILED, $queued->fresh()->status);
        $this->assertStringContainsString('was not built in the region within 16 minutes', (string) $queued->fresh()->message);
        $this->assertSame('shut_down', $live->fresh()->status);
    }

    /**
     * The runtime registry answers the project's runtime version image with $image (which may
     * throw); the deploy itself is not run (DeploymentCreated faked).
     */
    private function runtimeImage(Project $project, \Closure $image): void
    {
        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);
        $manifest = app(RuntimeRegistry::class)->forProject($project);
        $runtimes = Mockery::mock(RuntimeRegistry::class);
        $runtimes->shouldReceive('forProject')->andReturn($manifest);
        $runtimes->shouldReceive('executorFor')->andReturn(new ExecutorImage(['runtime' => 'python', 'version' => '3.12', 'docker_image' => 'ghcr.io/brf-tech/runr/python:3.12']));
        $runtimes->shouldReceive('versionImage')->andReturnUsing($image);
        $this->app->instance(RuntimeRegistry::class, $runtimes);
    }

    private function gate(array $violations): void
    {
        $decision = new PolicyDecision(true, $violations, ['mode' => 'enforce'], null);
        $this->mock(DeployPolicyGate::class, fn ($mock) => $mock->shouldReceive('evaluate')->andReturn($decision));
    }
}
