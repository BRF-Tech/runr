<?php

namespace Tests\Feature;

use App\Events\DeploymentStatusChanged;
use App\Jobs\BuildVersionJob;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\Git\GitDeploy;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\User;
use App\Models\Version;
use App\Services\GitDeploy\CommitMeta;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\SourceTree;
use App\Services\GitDeploy\VersionImporter;
use App\Services\Projects\ProjectStopService;
use App\Services\Projects\QueuedDeploys;
use App\Services\Projects\VersionRebuilds;
use App\Services\RegionMigrateService;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesRegionMigrationClusters;
use Tests\TestCase;

/**
 * After a region moved to a cluster with a registry of its own, the versions built before the
 * move — except the live one, which the move built again — name the old cluster's registry,
 * which the new cluster's nodes do not pull from. A deploy of such a version (the console's
 * deploy and rollback, the API's and the CLI's, a git redeploy of an earlier tree, a resume)
 * builds it again into the region's registry and deploys it when the build is ready, through the
 * deploy checks of every deploy, instead of failing at the image pull.
 */
class RegionMigrateOlderVersionsTest extends TestCase
{
    use FakesRegionMigrationClusters, RefreshDatabase;

    private const OLD_ZOT = '10.96.1.1:5000';

    private const NEW_ZOT = '10.96.2.2:5000';

    private KnativeRegion $region;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class]);
        $this->fakeClusters();
        $this->owner = User::factory()->create(); // the first user: the install's Owner

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

    public function test_deploying_an_older_version_after_the_move_builds_it_again_and_deploys_it_when_ready(): void
    {
        [$project, $v1] = $this->movedProject('orders');

        $answer = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id, 'deploy_config' => ['min_scale' => 3]])
            ->assertStatus(202)
            ->json();

        $this->assertSame(VersionRebuilds::message($v1), $answer['message']);
        $this->assertStringContainsString("v1 is built again for this region's registry", $answer['message']);
        $queued = QueuedDeploy::query()->findOrFail($answer['queued_deploy']['id']);
        $this->assertSame([QueuedDeploy::WAITING, QueuedDeploys::REASON_REBUILD, 1, self::OLD_ZOT.'/runr-builds/orders:v1'],
            [$answer['queued_deploy']['status'], $answer['queued_deploy']['reason'], $answer['queued_deploy']['version'], $queued->previous_build_image]);
        $this->assertSame('pending', $v1->fresh()->build_status, 'built again for the region\'s registry');
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->version->is($v1) && $job->initiatorId === (string) $this->owner->id);
        $this->assertSame(0, Deployment::query()->where('version_id', $v1->id)->count(), 'nothing it cannot pull is deployed');
        $audit = AuditEvent::query()->where('action', 'deployment.rollback')->sole();
        $this->assertSame($queued->id, $audit->metadata['queued_deploy_id']);

        $follow = $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/queued-deploys/{$queued->id}")->assertOk();
        $this->assertSame(QueuedDeploy::WAITING, $follow->json('data.status'));
        $this->assertStringContainsString('no-store', (string) $follow->headers->get('Cache-Control'));

        $this->builtOnTheNewCluster($v1->fresh(), self::NEW_ZOT.'/runr-builds/orders:v1');

        $done = $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/queued-deploys/{$queued->id}")->assertOk();
        $this->assertSame(QueuedDeploy::DEPLOYED, $done->json('data.status'));
        $deployment = Deployment::query()->findOrFail($done->json('data.deployment_id'));
        $this->assertSame(['active', $v1->id, ['min_scale' => 3], $this->owner->username],
            [$deployment->status, $deployment->version_id, $deployment->deploy_config, $deployment->deployed_by]);
        $this->assertSame(self::NEW_ZOT.'/runr-builds/orders:v1', $this->appliedImage('new.cluster.test', 'orders'));
        $this->assertSame(1, $project->deployments()->whereIn('status', ['active', 'pending', 'running'])->count());
    }

    public function test_a_version_in_the_regions_registry_deploys_at_once(): void
    {
        [$project, , $v2] = $this->movedProject('orders');

        $answer = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v2->id])
            ->assertStatus(202)
            ->json();

        $this->assertArrayNotHasKey('queued_deploy', $answer);
        $this->assertSame($v2->id, Deployment::query()->findOrFail($answer['id'])->version_id);
        $this->assertSame(1, QueuedDeploy::query()->count(), 'only the move\'s');
    }

    public function test_a_deploy_check_that_refuses_the_rebuilt_version_ends_the_deploy_and_tells_the_user(): void
    {
        [$project, $v1] = $this->movedProject('orders');
        $answer = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id])
            ->assertStatus(202)
            ->json();
        ProjectAdmission::guardDeploys(fn () => throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422)));

        $this->builtOnTheNewCluster($v1->fresh(), self::NEW_ZOT.'/runr-builds/orders:v1');

        $queued = QueuedDeploy::query()->findOrFail($answer['queued_deploy']['id']);
        $this->assertSame([QueuedDeploy::FAILED, 'Over the allocation.'], [$queued->status, $queued->message]);
        $live = $project->deployments()->where('status', 'active')->sole();
        $this->assertNotSame($v1->id, $live->version_id, 'what ran keeps running');
        $notice = Notification::query()->where('type', 'deployment.queued_failed')->sole();
        $this->assertSame([(string) $this->owner->id, 'critical', 'Deploy of orders v1 did not start', 'Over the allocation.'],
            [(string) $notice->user_id, $notice->level, $notice->title, $notice->body]);
    }

    public function test_a_refused_deploy_of_an_older_version_during_the_move_leaves_the_project_not_moved(): void
    {
        [$project, $v1, $v2] = $this->builtProject('orders');
        $this->move();
        $live = $project->deployments()->where('status', 'active')->sole();
        $move = QueuedDeploy::query()->sole();
        $this->assertSame([QueuedDeploys::REASON_REGION_MIGRATION, $v2->id], [$move->reason, $move->version_id], 'the move waits for v2 built again');

        // v1 is deployed while the move's deploy waits: the newer deploy replaces it
        Carbon::setTestNow('2026-10-19 10:05:00');
        $answer = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id])
            ->assertStatus(202)
            ->json();
        $queued = QueuedDeploy::query()->findOrFail($answer['queued_deploy']['id']);
        $this->assertSame([QueuedDeploy::CANCELLED, 'Replaced by a newer queued deploy of the project.'], [$move->fresh()->status, $move->fresh()->message]);
        $this->assertSame([QueuedDeploys::REASON_REBUILD, QueuedDeploys::REASON_REGION_MIGRATION, $live->id],
            [$queued->reason, $queued->replaces_reason, $queued->replaces_deployment_id], 'it takes the move\'s place');
        $this->assertSame('active', $live->fresh()->status, 'it runs on the old cluster until then');
        $this->assertSame([['building', 1]], $this->outcomes());

        ProjectAdmission::guardDeploys(fn () => throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422)));
        $this->builtOnTheNewCluster($v1->fresh(), self::NEW_ZOT.'/runr-builds/orders:v1');

        $this->assertSame([QueuedDeploy::FAILED, 'Over the allocation.'], [$queued->fresh()->status, $queued->fresh()->message]);
        $live->refresh();
        $this->assertSame(['shut_down', false, RegionMigrateService::NOT_MOVED.'Over the allocation.'], [$live->status, $live->knative_ready, $live->knative_message],
            'as if the move\'s own deploy had been refused');
        $this->assertNotNull($live->ended_at, 'not metered on the cluster the region left');
        $this->assertSame(0, $project->deployments()->whereIn('status', Deployment::LIVE)->count());
        $this->assertSame([['not_moved', 1, null, 'Over the allocation.']],
            array_map(fn (array $row) => [$row['outcome'], $row['version'], $row['deployment_status'], $row['message']], $this->report()));
        $this->assertSame('Over the allocation.', Notification::query()->where('type', 'deployment.queued_failed')->sole()->body, 'the user who asked for it hears it');

        // The rollback deploys it again on the old cluster, like a project the move refused
        ProjectAdmission::reset();
        Carbon::setTestNow('2026-10-19 11:00:00');
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh());

        $restored = $project->deployments()->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame(['active', $v2->id, ['min_scale' => 2]], [$restored->status, $restored->version_id, $restored->deploy_config]);
        $this->assertSame(self::OLD_ZOT.'/runr-builds/orders:v2', $this->appliedImage('old.cluster.test', 'orders'));
    }

    public function test_a_deploy_of_an_older_version_during_the_move_that_starts_moves_the_project(): void
    {
        [$project, $v1, $v2] = $this->builtProject('orders');
        $this->move();
        $live = $project->deployments()->where('status', 'active')->sole();

        Carbon::setTestNow('2026-10-19 10:05:00');
        $answer = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id, 'deploy_config' => ['min_scale' => 3]])
            ->assertStatus(202)
            ->json();
        $this->builtOnTheNewCluster($v1->fresh(), self::NEW_ZOT.'/runr-builds/orders:v1');

        $queued = QueuedDeploy::query()->findOrFail($answer['queued_deploy']['id']);
        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->status);
        $deployment = Deployment::query()->findOrFail($queued->deployment_id);
        $this->assertSame(['active', $v1->id, ['min_scale' => 3]], [$deployment->status, $deployment->version_id, $deployment->deploy_config]);
        $this->assertSame(self::NEW_ZOT.'/runr-builds/orders:v1', $this->appliedImage('new.cluster.test', 'orders'));
        $live->refresh();
        $this->assertSame('shutting_down', $live->status, 'replaced like by any deploy');
        $this->assertStringStartsWith(RegionMigrateService::MOVING, (string) $live->knative_message, 'not shut down as not moved');
        $this->assertSame([['deployed', 1, 'active']],
            array_map(fn (array $row) => [$row['outcome'], $row['version'], $row['deployment_status']], $this->report()));

        // The build of v2 the move started ends later: its deploy was replaced, nothing else is deployed
        $this->builtOnTheNewCluster($v2->fresh(), self::NEW_ZOT.'/runr-builds/orders:v2');
        $this->assertSame($deployment->id, $project->deployments()->whereIn('status', Deployment::LIVE)->sole()->id);
        $this->assertSame([['deployed', 1]], $this->outcomes());
    }

    public function test_a_git_redeploy_of_an_earlier_tree_builds_its_version_again_and_deploys_it(): void
    {
        [$project, $v1] = $this->movedProject('orders');
        // The deploy branch's head has the tree of v1 again
        $sha = str_repeat('b', 40);
        $this->mock(ProjectRemote::class, function ($mock) use ($sha) {
            $mock->shouldReceive('resolve')->andReturn($sha);
            $mock->shouldReceive('commit')->andReturn(['author_name' => 'Dev', 'author_email' => 'dev@example.test', 'message' => 'revert', 'subject' => 'revert']);
            $mock->shouldReceive('tree')->andReturn(SourceTree::fromRepository(['main.py' => 'def handler(e): return 1']));
        });
        $this->mock(VersionImporter::class, fn ($mock) => $mock->shouldReceive('import')
            ->andReturnUsing(fn (Project $p, SourceTree $tree, CommitMeta $meta) => ['version' => $v1->fresh(), 'created' => false, 'warnings' => [], 'skipped' => [], 'applied' => []]));

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/git/redeploy")->assertStatus(202);

        $deploy = GitDeploy::query()->where('sha', $sha)->sole();
        $this->assertSame([GitDeploy::BUILDING, VersionRebuilds::message($v1)], [$deploy->status, $deploy->status_message]);
        $this->assertSame('pending', $v1->fresh()->build_status);
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->version->is($v1));
        $this->assertSame(1, QueuedDeploy::query()->count(), 'the git deploy follows its version itself');

        // The build starts: the deploy still says why it waits
        $v1->refresh()->forceFill(['build_status' => 'building', 'build_image' => self::NEW_ZOT.'/runr-builds/orders:v1'])->save();
        $this->assertSame([GitDeploy::BUILDING, VersionRebuilds::message($v1)], [$deploy->fresh()->status, $deploy->fresh()->status_message]);

        $this->builtOnTheNewCluster($v1->fresh(), self::NEW_ZOT.'/runr-builds/orders:v1');

        $deploy->refresh();
        $this->assertSame(GitDeploy::ACTIVE, $deploy->status);
        $this->assertSame($v1->id, Deployment::query()->findOrFail($deploy->deployment_id)->version_id);
        $this->assertSame(self::NEW_ZOT.'/runr-builds/orders:v1', $this->appliedImage('new.cluster.test', 'orders'));
    }

    public function test_a_project_stopped_before_the_move_builds_its_version_again_when_it_is_resumed(): void
    {
        [$project, $v1, $v2] = $this->builtProject('orders');
        $project->deployments()->update(['status' => 'shut_down']);
        $project->forceFill(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill', 'stopped_version_id' => $v2->id, 'stopped_by' => (string) $this->owner->id])->saveQuietly();
        $this->move();
        $this->assertSame(0, QueuedDeploy::query()->count(), 'a stopped project is not deployed by the move');
        app(RegionMigrateService::class)->completeMigration($this->region->fresh());

        $answer = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/resume")->assertOk()->json();

        $this->assertNull($answer['deployment_id']);
        $this->assertSame([ProjectStopService::VERSION_REBUILDING, VersionRebuilds::message($v2)], [$answer['redeploy_code'], $answer['redeploy_error']]);
        $this->assertFalse($project->fresh()->isStopped());
        $queued = QueuedDeploy::query()->sole();
        $this->assertSame([QueuedDeploys::REASON_REBUILD, $v2->id], [$queued->reason, $queued->version_id]);

        $this->builtOnTheNewCluster($v2->fresh(), self::NEW_ZOT.'/runr-builds/orders:v2');

        $this->assertSame(QueuedDeploy::DEPLOYED, $queued->fresh()->status);
        $this->assertSame('active', Deployment::query()->findOrFail($queued->fresh()->deployment_id)->status);
        $this->assertSame(['min_scale' => 2], Deployment::query()->findOrFail($queued->fresh()->deployment_id)->deploy_config, 'with the overrides it ran with');
    }

    public function test_a_resume_the_deploy_checks_refuse_builds_nothing(): void
    {
        [$project, , $v2] = $this->builtProject('orders');
        $project->deployments()->update(['status' => 'shut_down']);
        $project->forceFill(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill', 'stopped_version_id' => $v2->id])->saveQuietly();
        $this->move();
        ProjectAdmission::guardDeploys(fn () => throw new HttpResponseException(response()->json(['message' => 'Over the allocation.', 'code' => 'fixed_allocation_exceeded'], 422)));

        $answer = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/resume")->assertOk()->json();

        $this->assertSame(['Over the allocation.', 'fixed_allocation_exceeded'], [$answer['redeploy_error'], $answer['redeploy_code']]);
        $this->assertSame('ready', $v2->fresh()->build_status);
        $this->assertSame(0, QueuedDeploy::query()->count());
    }

    public function test_a_rollback_of_the_move_gives_a_version_built_again_for_a_deploy_its_old_image_back(): void
    {
        [$project, $v1] = $this->builtProject('orders');
        $this->move();
        // An older version is deployed while the region runs on the new cluster
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id])->assertStatus(202);
        $v1->refresh()->forceFill(['build_status' => 'building', 'build_image' => self::NEW_ZOT.'/runr-builds/orders:v1'])->save();
        $queued = QueuedDeploy::query()->where('reason', QueuedDeploys::REASON_REBUILD)->sole();

        Carbon::setTestNow('2026-10-19 10:30:00');
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh());

        $this->assertSame(QueuedDeploy::CANCELLED, $queued->fresh()->status);
        $this->assertSame(['ready', self::OLD_ZOT.'/runr-builds/orders:v1'], [$v1->fresh()->build_status, $v1->fresh()->build_image],
            'the old cluster\'s registry has it');
        $this->assertContains('DELETE new.cluster.test /apis/batch/v1/namespaces/fn/jobs/build-orders-v1', $this->calls);
    }

    public function test_a_deploy_without_the_regions_registry_or_on_a_demo_region_is_not_built_again(): void
    {
        [$project, $v1] = $this->builtProject('orders');
        $this->assertFalse(VersionRebuilds::needed($project, $v1), 'in the region\'s registry');

        $this->region->update(['registry_address' => self::NEW_ZOT]);
        $project = $project->fresh();
        $this->assertTrue(VersionRebuilds::needed($project, $v1));
        $this->assertFalse(VersionRebuilds::needed($project, (clone $v1)->forceFill(['build_status' => 'building'])), 'only a ready build');
        $this->assertFalse(VersionRebuilds::needed($project, (clone $v1)->forceFill(['build_image' => null])), 'runs its runtime\'s image');

        $this->region->update(['is_demo' => true]);
        $this->assertFalse(VersionRebuilds::needed($project->fresh(), $v1));
        $this->region->update(['is_demo' => false, 'registry_address' => null]);
        $this->assertFalse(VersionRebuilds::needed($project->fresh(), $v1));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The move at 10:00 to the new cluster, whose Zot serves at NEW_ZOT; BuildVersionJob only queued. */
    private function move(): void
    {
        $this->migrateRegion($this->region->fresh(), self::NEW_ZOT);
        $this->assertSame('dns_check', $this->region->fresh()->migration_status);
    }

    /** @return list<array<string, mixed>> the projects of GET migration-status of the region */
    private function report(): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/knative-regions/{$this->region->id}/migration-status")
            ->assertOk()
            ->json('projects');
    }

    /** @return list<array{0: string, 1: int|null}> outcome and version of each project of report() */
    private function outcomes(): array
    {
        return array_map(fn (array $row) => [$row['outcome'], $row['version']], $this->report());
    }

    /**
     * A FaaS project whose v1 and v2 were built into the old cluster's registry, running v2 since
     * 09:00 with two pods.
     *
     * @return array{Project, Version, Version}
     */
    private function builtProject(string $name): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        // room for the min_scale overrides of the deploys below (min_scale may not pass max_scale)
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http', 'max_scale' => 5]);
        [$v1, $v2] = array_map(fn (int $n) => Version::create(['project_id' => $project->id, 'number' => $n, 'commit_id' => "c{$n}", 'created_by' => 'dev',
            'build_status' => 'ready', 'build_image' => self::OLD_ZOT."/runr-builds/{$project->slug}:v{$n}"]), [1, 2]);

        Carbon::setTestNow('2026-10-19 09:00:00');
        $project->deployments()->create([
            'version_id' => $v2->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => "run-{$project->slug}-v2", 'deployed_by' => 'dev', 'deploy_config' => ['min_scale' => 2],
        ]);
        $this->services['old.cluster.test'][] = $project->slug;

        return [$project->fresh(), $v1, $v2];
    }

    /**
     * builtProject() after a completed move: v2 was built again on the new cluster and runs there,
     * v1 is still in the old cluster's registry.
     *
     * @return array{Project, Version, Version}
     */
    private function movedProject(string $name): array
    {
        [$project, $v1, $v2] = $this->builtProject($name);
        $this->move();
        $this->builtOnTheNewCluster($v2->fresh(), self::NEW_ZOT."/runr-builds/{$project->slug}:v2");
        $this->assertSame(QueuedDeploy::DEPLOYED, QueuedDeploy::query()->sole()->status);
        app(RegionMigrateService::class)->completeMigration($this->region->fresh());
        Carbon::setTestNow('2026-10-21 09:00:00');

        return [$project->fresh(), $v1->fresh(), $v2->fresh()];
    }
}
