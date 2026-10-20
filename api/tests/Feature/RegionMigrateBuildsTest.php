<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Events\PlatformEvent;
use App\Jobs\BuildVersionJob;
use App\Jobs\CheckBuildReadyJob;
use App\Jobs\CheckRuntimeImageBuildJob;
use App\Jobs\ProcessQueuedDeployJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\Git\GitDeploy;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\RuntimeBuild;
use App\Models\UploadedImage;
use App\Models\User;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\GitDeploy\GitDeployService;
use App\Services\GitService;
use App\Services\Projects\QueuedDeploys;
use App\Services\RegionMigrateService;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesRegionMigrationClusters;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * A region moved to a cluster with its own registry: the old cluster's registry has the images
 * built for the region, and the new cluster's nodes do not pull from it.
 *
 *  - A built version (dependencies, a compile step, WebAssembly) is built again into the new
 *    registry and deployed there when the build is done, through the deploy checks of every
 *    deploy; the project runs on the old cluster until then, and a failed build or deploy leaves
 *    it not moved, with the reason. A version without a build of its own on a runtime image RunR
 *    built in the region waits for that image to be built again. A build running at the switch is
 *    submitted again on the new cluster.
 *  - An uploaded image cannot be built again: a CaaS project on one is not moved (and, after a
 *    rollback, not deployed again on the old cluster when it was uploaded on the new one), with
 *    the reason.
 *  - The rollback gives a version built again its old image back, cancels the deploy that waits
 *    for the build, cancels a build still running on the new cluster (cancelled, not failed) and
 *    restores the runtime image builds.
 *  - GET migration-status says per project what happened.
 */
class RegionMigrateBuildsTest extends TestCase
{
    use FakesRegionMigrationClusters, RefreshDatabase, RegistersRuntimes;

    private const OLD_ZOT = '10.96.1.1:5000';

    private const NEW_ZOT = '10.96.2.2:5000';

    private const DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private KnativeRegion $region;

    /** The first user of the install: its Owner */
    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class]);
        $this->fakeClusters();

        $this->region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true,
            'kubeconfig' => $this->kubeconfig(self::OLD),
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
            'registry_mode' => 'internal', 'registry_status' => 'ready',
            'registry_address' => self::OLD_ZOT, 'registry_namespace' => 'runr-registry',
        ]);
    }

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_built_version_is_built_again_on_the_new_cluster_and_deployed_there_when_it_is_ready(): void
    {
        [$project, $before] = $this->builtProject('orders');

        $this->move();

        $version = Version::query()->findOrFail($before->version_id);
        $this->assertSame('pending', $version->build_status, 'built again on the new cluster');
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->version->is($version));
        $queued = QueuedDeploy::query()->sole();
        $this->assertSame([QueuedDeploy::WAITING, QueuedDeploys::REASON_REGION_MIGRATION, $before->id, self::OLD_ZOT.'/runr-builds/orders:v1'],
            [$queued->status, $queued->reason, $queued->replaces_deployment_id, $queued->previous_build_image]);
        $before->refresh();
        $this->assertSame('active', $before->status, 'it runs on the old cluster until then');
        $this->assertSame(RegionMigrateService::MOVING.'v1 is built again there', $before->knative_message);
        $this->assertNotContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/orders', $this->calls, 'nothing it cannot pull');
        $this->assertSame([['organization' => null, 'project_id' => $project->id, 'project' => 'orders', 'outcome' => 'building', 'version' => 1,
            'deployment_status' => null, 'message' => 'Moving to the new cluster of the region: v1 is built again there; deployed there when it is ready.']],
            $this->migrationStatus()['projects']);

        $this->builtOnTheNewCluster($version, self::NEW_ZOT.'/runr-builds/orders:v1');

        $queued->refresh();
        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->status);
        $moved = Deployment::query()->findOrFail($queued->deployment_id);
        $this->assertSame(['active', 'region-migration', ['min_scale' => 2]], [$moved->status, $moved->deployed_by, $moved->deploy_config]);
        $this->assertSame(self::NEW_ZOT.'/runr-builds/orders:v1', $this->appliedImage('new.cluster.test', 'orders'));
        $this->assertSame('shutting_down', $before->fresh()->status);
        $this->assertSame(['deployed', 'active'], [$this->migrationStatus()['projects'][0]['outcome'], $this->migrationStatus()['projects'][0]['deployment_status']]);
    }

    public function test_a_build_that_fails_on_the_new_cluster_leaves_the_project_not_moved_and_the_rollback_runs_it_again(): void
    {
        [$project, $before] = $this->builtProject('orders');
        $this->move();
        $version = Version::query()->findOrFail($before->version_id);

        $this->builtOnTheNewCluster($version, self::NEW_ZOT.'/runr-builds/orders:v1', 'pip: No matching distribution found for left-pad==9');

        $before->refresh();
        $this->assertSame('shut_down', $before->status);
        $this->assertSame(RegionMigrateService::NOT_MOVED.'v1 could not be built: pip: No matching distribution found for left-pad==9', $before->knative_message);
        $row = $this->migrationStatus()['projects'][0];
        $this->assertSame(['not_moved', 'V1 could not be built: pip: No matching distribution found for left-pad==9'], [$row['outcome'], $row['message']]);

        Carbon::setTestNow('2026-10-19 11:00:00');
        $this->rollback();

        $version->refresh();
        $this->assertSame(['ready', self::OLD_ZOT.'/runr-builds/orders:v1', null], [$version->build_status, $version->build_image, $version->build_error],
            'the image the old cluster\'s registry has');
        $restored = $project->deployments()->where('status', 'active')->sole();
        $this->assertSame([$version->id, ['min_scale' => 2]], [$restored->version_id, $restored->deploy_config]);
        $this->assertSame(self::OLD_ZOT.'/runr-builds/orders:v1', $this->appliedImage('old.cluster.test', 'orders'));
    }

    public function test_a_rollback_while_the_version_builds_cancels_its_deploy_and_runs_the_old_image(): void
    {
        [$project, $before] = $this->builtProject('orders');
        $this->move();
        $version = Version::query()->findOrFail($before->version_id);
        $version->forceFill(['build_status' => 'building', 'build_image' => self::NEW_ZOT.'/runr-builds/orders:v1'])->save();

        Carbon::setTestNow('2026-10-19 10:20:00');
        $this->rollback();

        $queued = QueuedDeploy::query()->sole();
        $this->assertSame(QueuedDeploy::CANCELLED, $queued->status);
        $this->assertStringStartsWith('Cancelled by the migration rollback', (string) $queued->message);
        $version->refresh();
        $this->assertSame(['ready', self::OLD_ZOT.'/runr-builds/orders:v1'], [$version->build_status, $version->build_image]);
        $this->assertContains('DELETE new.cluster.test /apis/batch/v1/namespaces/fn/jobs/build-orders-v1', $this->calls, 'its Kaniko Job stops');
        $live = $project->deployments()->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame(['active', '2026-10-19 10:20:00'], [$live->status, $live->ready_at?->toDateTimeString()]);
        $this->assertSame(self::OLD_ZOT.'/runr-builds/orders:v1', $this->appliedImage('old.cluster.test', 'orders'));

        // The build check of the stopped build finds nothing to fail
        (new CheckBuildReadyJob($version))->handle();
        $this->assertSame('ready', $version->fresh()->build_status);
    }

    public function test_a_rollback_cancels_a_queued_deploy_that_is_starting_but_has_not_created_its_deployment(): void
    {
        [$project, $before] = $this->builtProject('orders');
        $this->move();
        $version = Version::query()->findOrFail($before->version_id);
        $queued = QueuedDeploy::query()->sole();

        // The rollback comes while the deploy of the version built again runs its checks
        $rolledBack = false;
        ProjectAdmission::guardDeploys(function () use (&$rolledBack, $queued) {
            if (! $rolledBack && QueuedDeploy::query()->find($queued->id)?->status === QueuedDeploy::STARTING) {
                $rolledBack = true;
                Carbon::setTestNow('2026-10-19 10:20:00');
                $this->rollback();
            }
        });

        $this->builtOnTheNewCluster($version, self::NEW_ZOT.'/runr-builds/orders:v1');

        $this->assertTrue($rolledBack);
        $queued->refresh();
        $this->assertSame([QueuedDeploy::CANCELLED, null], [$queued->status, $queued->deployment_id]);
        $this->assertSame('Cancelled by the migration rollback: the version runs from the image the restored cluster\'s registry has.', $queued->message);
        $this->assertNotContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/orders', $this->calls, 'the step it was claimed by started nothing');
        $live = $project->deployments()->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame(['region-migration', 'active', $version->id], [$live->deployed_by, $live->status, $live->version_id]);
        $this->assertSame(self::OLD_ZOT.'/runr-builds/orders:v1', $this->appliedImage('old.cluster.test', 'orders'));
    }

    public function test_a_rollback_after_the_starting_deploy_created_its_deployment_takes_over_from_it(): void
    {
        [$project, $before] = $this->builtProject('orders');
        $this->move();
        $version = Version::query()->findOrFail($before->version_id);
        $queued = QueuedDeploy::query()->sole();

        // The rollback comes right after the deploy created its deployment on the new cluster
        $rolledBack = false;
        Event::listen(DeploymentCreated::class, function () use (&$rolledBack) {
            if (! $rolledBack) {
                $rolledBack = true;
                Carbon::setTestNow('2026-10-19 10:20:00');
                $this->rollback();
            }
        });

        $this->builtOnTheNewCluster($version, self::NEW_ZOT.'/runr-builds/orders:v1');

        $this->assertTrue($rolledBack);
        $queued->refresh();
        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->status, 'not cancelled: its deployment exists');
        $started = Deployment::query()->findOrFail($queued->deployment_id);
        $this->assertContains($started->status, ['shut_down', 'shutting_down'], 'replaced by the rollback\'s redeploy');
        $live = $project->deployments()->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame(['region-migration', 'active', '2026-10-19 10:20:00'], [$live->deployed_by, $live->status, $live->created_at?->toDateTimeString()]);
        $this->assertSame(self::OLD_ZOT.'/runr-builds/orders:v1', $this->appliedImage('old.cluster.test', 'orders'));
    }

    public function test_a_build_still_running_on_the_new_cluster_at_the_rollback_is_cancelled_not_failed(): void
    {
        $this->move();
        // Saved in the editor while the region ran on the new cluster: its Kaniko Job runs there
        Carbon::setTestNow('2026-10-19 10:30:00');
        $project = Project::create(['name' => 'fresh', 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev',
            'build_status' => 'building', 'build_image' => self::NEW_ZOT.'/runr-builds/fresh:v1']);
        $push = GitDeploy::create(['project_id' => $project->id, 'version_id' => $version->id, 'kind' => GitDeploy::KIND_PRODUCTION,
            'trigger' => 'push', 'provider' => 'runr', 'sha' => str_repeat('a', 40), 'status' => GitDeploy::BUILDING]);
        Event::fake([DeploymentStatusChanged::class, PlatformEvent::class]);

        $this->rollback();

        $version->refresh();
        $this->assertSame(Version::STATUS_CANCELLED, $version->build_status);
        $this->assertSame(RegionMigrateService::BUILD_CANCELLED, $version->build_error);
        $this->assertContains('DELETE new.cluster.test /apis/batch/v1/namespaces/fn/jobs/build-fresh-v1', $this->calls);
        $push->refresh();
        $this->assertSame(GitDeploy::SKIPPED, $push->status, 'not a failed deploy');
        $this->assertSame(GitDeployService::BUILD_CANCELLED.RegionMigrateService::BUILD_CANCELLED, $push->status_message);

        // Its build check ends without a failure
        (new CheckBuildReadyJob($version))->handle();
        $this->assertSame(Version::STATUS_CANCELLED, $version->fresh()->build_status);
        Event::assertNotDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'build.failed');
    }

    public function test_a_build_started_before_the_rollback_of_a_move_to_the_same_cluster_goes_on(): void
    {
        [$project] = $this->builtProject('orders');
        // Another kubeconfig of the same cluster, whose Zot serves the region already
        $this->migrateRegion($this->region->fresh(), self::OLD_ZOT, self::OLD);
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'building',
            'build_image' => self::OLD_ZOT.'/runr-builds/orders:v2']);

        $this->rollback();

        $this->assertSame('building', $v2->fresh()->build_status);
        $this->assertSame(0, QueuedDeploy::query()->count(), 'nothing was built again: the registry stayed');
    }

    public function test_a_build_running_when_the_region_switches_is_submitted_again_on_the_new_cluster(): void
    {
        [$project] = $this->builtProject('orders');
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'building',
            'build_image' => self::OLD_ZOT.'/runr-builds/orders:v2']);

        $this->move();

        $this->assertSame('pending', $v2->fresh()->build_status);
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->version->is($v2));

        // Submitted on the new cluster, the build has checks of its own: the old submission's stop
        // instead of timing the new build out from the old one's start
        $v2->refresh()->forceFill(['build_status' => 'building', 'build_image' => self::NEW_ZOT.'/runr-builds/orders:v2'])->save();
        Bus::fake([CheckBuildReadyJob::class]);
        CheckBuildReadyJob::start($v2);
        $token = (string) $v2->fresh()->build_token;
        $this->assertSame(32, strlen($token));
        Bus::assertDispatched(CheckBuildReadyJob::class, fn (CheckBuildReadyJob $job) => $job->token === $token && $job->attempt === 1);

        (new CheckBuildReadyJob($v2->fresh(), CheckBuildReadyJob::maxAttempts(), null, false, 'checks-of-the-old-cluster'))->handle();
        $this->assertSame('building', $v2->fresh()->build_status, 'not timed out by the old submission\'s checks');
        (new CheckBuildReadyJob($v2->fresh(), CheckBuildReadyJob::maxAttempts(), null, false, $token))->handle();
        $this->assertSame('failed', $v2->fresh()->build_status, 'its own checks time it out');
    }

    public function test_a_version_on_a_runtime_image_built_in_the_region_waits_for_it_on_the_new_cluster(): void
    {
        $runtime = $this->registerRuntime($this->qManifest(), $this->qFiles());
        $spec = (new RuntimeManifest($runtime->manifest))->version('1.0');
        $digest = app(RuntimeImageBuildService::class)->digest($runtime, $spec);
        $build = RuntimeBuild::create(['runtime_id' => 'q', 'version' => '1.0', 'knative_region_id' => $this->region->id,
            'status' => RuntimeBuild::STATUS_READY, 'digest' => $digest, 'image' => self::OLD_ZOT.'/runr-runtimes/q:1.0-'.substr($digest, 0, 12)]);
        $oldImage = $build->image;
        [$project, $before] = $this->runtimeProjectOn('quotes', 'q');
        Bus::fake([BuildVersionJob::class, ProcessQueuedDeployJob::class, CheckRuntimeImageBuildJob::class]);

        $this->move();

        $build->refresh();
        $this->assertSame([RuntimeBuild::STATUS_PENDING, null], [$build->status, $build->image], 'built again into the new registry when needed');
        $this->assertSame($oldImage, $this->region->fresh()->migration_old_state[RegionMigrateService::RUNTIME_BUILDS][$build->id]['image'] ?? null);
        $queued = QueuedDeploy::query()->sole();
        $this->assertNull($queued->previous_build_image, 'the version itself is not built');
        $this->assertSame(RegionMigrateService::MOVING.'the runtime image q:1.0 is built again there', $before->fresh()->knative_message);

        // Its first check starts the runtime image build on the new cluster and waits for it
        (new ProcessQueuedDeployJob($queued->id))->handle(app(QueuedDeploys::class));
        $build->refresh();
        $this->assertSame(RuntimeBuild::STATUS_BUILDING, $build->status);
        $this->assertStringStartsWith(self::NEW_ZOT.'/runr-runtimes/q:1.0-', (string) $build->image);
        $this->assertSame([QueuedDeploy::WAITING, 1], [$queued->fresh()->status, $queued->fresh()->attempts]);

        $build->update(['status' => RuntimeBuild::STATUS_READY]);
        (new ProcessQueuedDeployJob($queued->id))->handle(app(QueuedDeploys::class));

        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->fresh()->status);
        $this->assertSame($build->image, $this->appliedImage('new.cluster.test', 'quotes'));

        // A rollback gives the runtime build its old image back
        $this->rollback();
        $build->refresh();
        $this->assertSame([RuntimeBuild::STATUS_READY, $oldImage], [$build->status, $build->image]);
    }

    public function test_a_caas_project_on_an_uploaded_image_is_not_moved_and_the_rollback_runs_it_again(): void
    {
        $upload = $this->upload(self::OLD_ZOT);
        [$project, $before] = $this->caasProject('shop', self::OLD_ZOT.'/runr-uploads/platform/myapp@'.self::DIGEST);

        $this->move();

        $before->refresh();
        $this->assertSame('shut_down', $before->status);
        $this->assertSame(RegionMigrateService::NOT_MOVED.'its image myapp:1.0 was uploaded into the registry of the cluster the region left; upload it to the region again, select it as the project\'s image and deploy',
            $before->knative_message);
        $this->assertNotContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/shop', $this->calls);
        $this->assertFalse($upload->inRegistryOf($this->region->fresh()), 'not in the new cluster\'s registry');
        $this->assertSame('not_moved', $this->migrationStatus()['projects'][0]['outcome']);

        $this->rollback();

        $this->assertTrue($upload->inRegistryOf($this->region->fresh()));
        $this->assertSame(1, $project->deployments()->where('status', 'active')->count(), 'deployed again on the old cluster');
        $this->assertSame(self::OLD_ZOT.'/runr-uploads/platform/myapp@'.self::DIGEST, $this->appliedImage('old.cluster.test', 'shop'));
    }

    public function test_a_caas_project_on_an_image_uploaded_on_the_new_cluster_is_not_deployed_again_by_the_rollback(): void
    {
        [$project] = $this->caasProject('shop', 'nginx:1.27-alpine');
        $this->move();

        // Uploaded while the region ran on the new cluster, and deployed there
        $this->upload(self::NEW_ZOT);
        $project->containerConfig->update(['image' => self::NEW_ZOT.'/runr-uploads/platform/myapp@'.self::DIGEST]);

        $this->rollback();

        $moved = $project->deployments()->where('deployed_by', 'region-migration')->sole();
        $this->assertSame('shut_down', $moved->status);
        $this->assertSame(RegionMigrateService::NOT_RESTORED.'its image myapp:1.0 was uploaded into the registry of the cluster the rollback left; upload it to the region again, select it as the project\'s image and deploy',
            $moved->knative_message);
        $this->assertContains('DELETE old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/shop', $this->calls, 'nothing of it serves unmetered');
    }

    public function test_a_code_project_on_an_uploaded_runtime_image_is_not_moved(): void
    {
        $this->upload(self::OLD_ZOT, 'bash-airgap', '5.2');
        $this->registerRuntime($this->bashManifest(['versions' => [['version' => '5.2', 'upload' => 'bash-airgap:5.2', 'default' => true, 'image' => null]]]));
        [, $before] = $this->runtimeProjectOn('script', 'bash');

        $this->move();

        $before->refresh();
        $this->assertSame('shut_down', $before->status);
        $this->assertStringStartsWith(RegionMigrateService::NOT_MOVED, (string) $before->knative_message);
        $this->assertStringContainsString('bash-airgap:5.2', (string) $before->knative_message);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The move at 10:00 to the new cluster, whose Zot serves at NEW_ZOT; BuildVersionJob only queued. */
    private function move(): void
    {
        $this->migrateRegion($this->region->fresh(), self::NEW_ZOT);
        $this->assertSame('dns_check', $this->region->fresh()->migration_status);
    }

    private function rollback(): void
    {
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh());
        $this->assertStringContainsString('old.cluster.test', (string) $this->region->fresh()->kubeconfig);
    }

    /** @return array<string, mixed> GET migration-status of the region */
    private function migrationStatus(): array
    {
        $this->owner ??= User::factory()->create();

        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/knative-regions/{$this->region->id}/migration-status")
            ->assertOk()
            ->json();
    }

    /**
     * A FaaS project running since 09:00 with two pods whose v1 was built into the old cluster's
     * registry — its Knative Service is on the old cluster.
     *
     * @return array{Project, Deployment}
     */
    private function builtProject(string $name): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev',
            'build_status' => 'ready', 'build_image' => self::OLD_ZOT."/runr-builds/{$project->slug}:v1"]);

        return [$project->fresh(), $this->running($project, $version)];
    }

    /** @return array{Project, Deployment} a CaaS project on $image, running on the old cluster */
    private function caasProject(string $name, string $image): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => $image, 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 2]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(), $this->running($project, $version)];
    }

    /**
     * An XaaS project of $runtime without a build of its own (it runs on the runtime version's
     * image), running on the old cluster.
     *
     * @return array{Project, Deployment}
     */
    private function runtimeProjectOn(string $name, string $runtime): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => $runtime]);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        $manifest = app(RuntimeRegistry::class)->require($runtime);
        XaasConfig::create(['project_id' => $project->id, 'filename' => $manifest->defaultFilename(), 'handler' => $manifest->handlerFunction(),
            'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30]);
        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('getAllFilesAtVersion')->andReturn([$manifest->defaultFilename() => 'x']));
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(), $this->running($project, $version)];
    }

    private function running(Project $project, Version $version): Deployment
    {
        Carbon::setTestNow('2026-10-19 09:00:00');
        $deployment = $project->deployments()->create([
            'version_id' => $version->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => "run-{$project->slug}-v1", 'deployed_by' => 'dev', 'deploy_config' => ['min_scale' => 2],
        ]);
        $this->services['old.cluster.test'][] = $project->slug;

        return $deployment;
    }

    /** The platform's upload of $repository:$tag into the region's registry at $registry. */
    private function upload(string $registry, string $repository = 'myapp', string $tag = '1.0'): UploadedImage
    {
        return UploadedImage::create([
            'owner_key' => UploadedImage::PLATFORM_OWNER, 'knative_region_id' => $this->region->id,
            'repository' => $repository, 'tag' => $tag, 'registry_repository' => "runr-uploads/platform/{$repository}",
            'digest' => self::DIGEST, 'media_type' => 'application/vnd.oci.image.manifest.v1+json', 'kind' => UploadedImage::KIND_IMAGE,
            'source_format' => 'oci', 'size_bytes' => 1024, 'archive_bytes' => 2048, 'layers' => 1, 'registry_address' => $registry,
        ]);
    }
}
