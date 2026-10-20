<?php

namespace Tests\Feature;

use App\Events\DeploymentStatusChanged;
use App\Jobs\BuildVersionJob;
use App\Jobs\CheckRuntimeImageBuildJob;
use App\Jobs\GitDeploy\BuildPreviewJob;
use App\Jobs\GitDeploy\CheckPreviewBuildJob;
use App\Jobs\GitDeploy\DeployPreviewJob;
use App\Jobs\GitDeploy\ReportGitStatusJob;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\Git\GitDeploy;
use App\Models\Git\PreviewEnvironment;
use App\Models\Git\VersionGitSource;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\RuntimeBuild;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\PreviewVersionScope;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\SourceTree;
use App\Services\RegionMigrateService;
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
 * The preview environments of pull/merge requests move with their region: the move deploys each
 * live preview again on the new cluster through the preview path (as its Redeploy does), building
 * a preview version again first when its image is in a registry the new cluster does not pull
 * from or its build ran on the old cluster; a preview whose build failed is closed with the
 * reason. A rollback does the same in reverse.
 */
class RegionMigratePreviewsTest extends TestCase
{
    use FakesRegionMigrationClusters, RefreshDatabase, RegistersRuntimes;

    private const OLD_ZOT = '10.96.1.1:5000';

    private const NEW_ZOT = '10.96.2.2:5000';

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class]);
        $this->fakeClusters();
        Bus::fake([BuildVersionJob::class, BuildPreviewJob::class, ReportGitStatusJob::class]);

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

    public function test_a_built_preview_is_built_again_on_the_new_cluster_and_deployed_there(): void
    {
        $project = $this->project('orders');
        [$preview, $version, $deploy] = $this->preview($project, 7, 'ready', self::OLD_ZOT.'/runr-builds/orders:v1000000001');

        $this->move();

        $preview->refresh();
        $message = 'Built again on the new cluster of the region: its image is in a registry that cluster does not pull from; deployed there when it is ready.';
        $this->assertSame([PreviewEnvironment::BUILDING, $message], [$preview->status, $preview->status_message]);
        $this->assertSame([GitDeploy::BUILDING, $message], [$deploy->fresh()->status, $deploy->fresh()->status_message]);
        $this->assertSame('pending', $version->fresh()->build_status);
        Bus::assertDispatched(BuildPreviewJob::class, fn (BuildPreviewJob $job) => $job->previewId === $preview->id && $job->versionId === $version->id);
        $this->assertNotContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/orders-pr-7', $this->calls, 'nothing it cannot pull');

        // The build on the new cluster is done (CheckPreviewBuildJob): the preview is applied there
        $version->refresh()->forceFill(['build_status' => 'ready', 'build_image' => self::NEW_ZOT.'/runr-builds/orders:v1000000001'])->save();
        (new DeployPreviewJob($preview->id))->handle(app(PreviewService::class));

        $this->assertSame(self::NEW_ZOT.'/runr-builds/orders:v1000000001', $this->appliedImage('new.cluster.test', 'orders-pr-7'));
        $this->assertSame(PreviewEnvironment::READY, $preview->fresh()->status);
        $this->assertSame(GitDeploy::ACTIVE, $deploy->fresh()->status);
    }

    public function test_a_preview_without_a_build_of_its_own_is_deployed_again_and_a_failed_one_is_closed(): void
    {
        Bus::fake([BuildVersionJob::class, BuildPreviewJob::class, ReportGitStatusJob::class, DeployPreviewJob::class]);
        $project = $this->project('orders');
        [$ready] = $this->preview($project, 7, 'ready', null);
        [$failed, , $failedDeploy] = $this->preview($project, 8, 'failed', null, PreviewEnvironment::FAILED);
        [$pending] = $this->preview($project, 9, 'pending', null, PreviewEnvironment::BUILDING);
        [$closed] = $this->preview($project, 10, 'ready', null, PreviewEnvironment::DELETED);

        $this->move();

        $this->assertSame(PreviewEnvironment::DEPLOYING, $ready->fresh()->status);
        Bus::assertDispatched(DeployPreviewJob::class, fn (DeployPreviewJob $job) => $job->previewId === $ready->id);
        $failed->refresh();
        $this->assertSame(PreviewEnvironment::FAILED, $failed->status);
        $this->assertSame(RegionMigrateService::NOT_MOVED.'the build of its version failed (failed); push a new commit to the request to build and deploy it there', $failed->status_message);
        $this->assertSame(GitDeploy::FAILED, $failedDeploy->fresh()->status);
        $this->assertSame(PreviewEnvironment::BUILDING, $pending->fresh()->status, 'its build runs where the region is now');
        $this->assertSame(PreviewEnvironment::DELETED, $closed->fresh()->status);
        Bus::assertNotDispatched(BuildPreviewJob::class);
        Bus::assertDispatchedTimes(DeployPreviewJob::class, 1);
    }

    public function test_a_preview_build_running_at_the_switch_is_built_again_and_the_rollback_builds_previews_again_on_the_old_cluster(): void
    {
        $project = $this->project('orders');
        [$building, $buildingVersion] = $this->preview($project, 7, 'building', self::OLD_ZOT.'/runr-builds/orders:v1000000001', PreviewEnvironment::BUILDING);

        $this->move();

        $this->assertSame('pending', $buildingVersion->fresh()->build_status);
        $this->assertStringContainsString('its build ran on the cluster the region left', (string) $building->fresh()->status_message);
        Bus::assertDispatchedTimes(BuildPreviewJob::class, 1);
        // Submitted on the new cluster with checks of its own (BuildPreviewJob): the checks of the
        // build on the old cluster stop instead of timing it out
        $buildingVersion->refresh()->forceFill(['build_status' => 'building', 'build_token' => 'checks-of-the-new-cluster'])->save();
        (new CheckPreviewBuildJob($building->id, $buildingVersion->id, CheckPreviewBuildJob::maxAttempts(), 'checks-of-the-old-cluster'))->handle(app(PreviewService::class));
        $this->assertSame([PreviewEnvironment::BUILDING, 'building'], [$building->fresh()->status, $buildingVersion->fresh()->build_status]);

        // Built and deployed on the new cluster; a second request's build still runs there at the rollback
        $buildingVersion->refresh()->forceFill(['build_status' => 'ready', 'build_image' => self::NEW_ZOT.'/runr-builds/orders:v1000000001'])->save();
        $building->refresh()->forceFill(['status' => PreviewEnvironment::READY, 'status_message' => null])->save();
        [$second, $secondVersion] = $this->preview($project, 8, 'building', self::NEW_ZOT.'/runr-builds/orders:v1000000002', PreviewEnvironment::BUILDING);

        Carbon::setTestNow('2026-10-19 10:30:00');
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh());

        foreach ([[$building, $buildingVersion], [$second, $secondVersion]] as [$preview, $version]) {
            $this->assertSame([PreviewEnvironment::BUILDING, 'pending'], [$preview->fresh()->status, $version->fresh()->build_status]);
            $this->assertStringStartsWith('Built again on the cluster the migration rollback restored: ', (string) $preview->fresh()->status_message);
        }
        Bus::assertDispatchedTimes(BuildPreviewJob::class, 3);
        $this->assertContains('DELETE new.cluster.test /apis/batch/v1/namespaces/fn/jobs/build-orders-v1000000002', $this->calls, 'its Kaniko Job stops');
        $this->assertNotContains('DELETE new.cluster.test /apis/batch/v1/namespaces/fn/jobs/build-orders-v1000000001', $this->calls, 'that build had ended');
    }

    public function test_a_preview_deploy_waits_for_the_runtime_image_the_region_builds(): void
    {
        Bus::fake([BuildVersionJob::class, BuildPreviewJob::class, ReportGitStatusJob::class, DeployPreviewJob::class, CheckRuntimeImageBuildJob::class]);
        $this->registerRuntime($this->qManifest(), $this->qFiles());
        $project = Project::create(['name' => 'quotes', 'service_type' => 'xaas', 'runtime' => 'q']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        $manifest = app(RuntimeRegistry::class)->require('q');
        XaasConfig::create(['project_id' => $project->id, 'filename' => $manifest->defaultFilename(), 'handler' => $manifest->handlerFunction(),
            'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30]);
        $this->mock(ProjectRemote::class, fn ($mock) => $mock->shouldReceive('tree')->andReturn(SourceTree::fromRepository([$manifest->defaultFilename() => 'x'])));
        [$preview] = $this->preview($project->fresh(), 3, 'ready', null);
        $this->assertSame(0, RuntimeBuild::query()->count());

        app(PreviewService::class)->deploy($preview->fresh());

        $this->assertSame(PreviewEnvironment::DEPLOYING, $preview->fresh()->status);
        $this->assertStringStartsWith('Runtime image q:1.0 is being built in region eu', (string) $preview->fresh()->status_message);
        Bus::assertDispatched(DeployPreviewJob::class, fn (DeployPreviewJob $job) => $job->previewId === $preview->id && $job->waited === 1);
        $this->assertNotContains('PATCH old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/quotes-pr-3', $this->calls);

        // Not built in time: the preview fails with the reason
        app(PreviewService::class)->deploy($preview->fresh(), PreviewService::DEPLOY_WAIT_ATTEMPTS);
        $this->assertSame(PreviewEnvironment::FAILED, $preview->fresh()->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The move at 10:00 to the new cluster, whose Zot serves at NEW_ZOT. */
    private function move(): void
    {
        $this->migrateRegion($this->region->fresh(), self::NEW_ZOT);
        $this->assertSame('dns_check', $this->region->fresh()->migration_status);
    }

    /** A FaaS project on the region that runs nothing in production (its previews are what moves). */
    private function project(string $name): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);

        return $project->fresh();
    }

    /**
     * Preview #$number of the project with its version (build $build, image $image) and the git
     * deploy of its head commit; a READY preview's Knative Service runs on the old cluster.
     *
     * @return array{PreviewEnvironment, Version, GitDeploy}
     */
    private function preview(Project $project, int $number, string $build, ?string $image, string $status = PreviewEnvironment::READY): array
    {
        $version = Version::create(['project_id' => $project->id, 'number' => PreviewVersionScope::nextNumber($project), 'commit_id' => "p{$number}",
            'created_by' => 'git', 'build_status' => $build, 'build_image' => $image]);
        VersionGitSource::create(['version_id' => $version->id, 'project_id' => $project->id, 'provider' => 'github', 'sha' => str_repeat((string) ($number % 10), 40), 'pr_number' => $number]);
        $preview = PreviewEnvironment::query()->create([
            'project_id' => $project->id, 'number' => $number, 'provider' => 'github', 'head_sha' => str_repeat((string) ($number % 10), 40),
            'status' => $status, 'service_name' => "{$project->slug}-pr-{$number}", 'version_id' => $version->id,
            'expires_at' => now()->addDay(),
        ]);
        $deploy = GitDeploy::create(['project_id' => $project->id, 'kind' => GitDeploy::KIND_PREVIEW, 'trigger' => 'pull_request', 'provider' => 'github',
            'sha' => $preview->head_sha, 'pr_number' => $number, 'preview_environment_id' => $preview->id, 'version_id' => $version->id,
            'status' => $status === PreviewEnvironment::READY ? GitDeploy::ACTIVE : GitDeploy::BUILDING]);
        if ($status === PreviewEnvironment::READY) {
            $this->services['old.cluster.test'][] = $preview->service_name;
        }
        $this->assertSame(0, Deployment::query()->where('project_id', $project->id)->count());

        return [$preview, $version, $deploy];
    }
}
