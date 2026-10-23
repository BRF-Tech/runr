<?php

namespace Tests\Feature\Runtimes;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\BuildVersionJob;
use App\Jobs\EnforceRuntimePatchesJob;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimePatchMigration;
use App\Models\User;
use App\Models\Version;
use App\Services\Projects\QueuedDeploys;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Deadlines of critical and zero-day patches (EnforceRuntimePatchesJob, RuntimePatchMigrations):
 * projects still behind are redeployed onto the newest patch once per patch, while the platform
 * policy allows it; a new revision that does not become ready is rolled back to the digest the
 * project ran before (and pinned there) with a critical notification; one that does tells the
 * owner. A prebuilt version is built again FROM the patch.
 */
class RuntimePatchDeadlineTest extends TestCase
{
    use ActsWithRoles, BuildsRuntimePatches, RefreshDatabase, RegistersRuntimes;

    private User $owner;

    private RuntimePatch $running;

    private RuntimePatch $zeroDay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);

        $this->owner = $this->userWithRole('owner');
        $this->running = $this->runtimePatch(['tag' => '3.14.6-r1', 'published_at' => now()->subDays(9)]);
        $this->zeroDay = $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'published_at' => now()->subDays(4), 'deadline_at' => now()->subHour()]);
    }

    /** @return array{0: Project, 1: Version, 2: Deployment} */
    private function liveProject(?string $buildImage = null): array
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x'], [], $buildImage);
        $project->forceFill(['owner_id' => $this->owner->id])->save();
        $live = $this->liveOn($project, $version, $this->running, now()->subDays(8), ['deploy_config' => ['min_scale' => 1]]);

        return [$project, $version, $live];
    }

    private function enforce(): void
    {
        (new EnforceRuntimePatchesJob)->handle(app(RuntimePatchMigrations::class), app(RuntimePatchNotifier::class));
    }

    public function test_at_the_deadline_projects_behind_are_redeployed_onto_the_newest_patch_once(): void
    {
        [$project, $version] = $this->liveProject();
        $project->forceFill(['runtime_patch_pin' => $this->running->id])->save();

        $this->enforce();
        $this->enforce();

        $migration = RuntimePatchMigration::query()->sole();
        $this->assertSame([RuntimePatchMigration::AUTO, RuntimePatchMigration::STARTED, $this->zeroDay->id, $this->running->digest],
            [$migration->trigger, $migration->status, $migration->patch_id, $migration->previous_digest]);
        $deployment = Deployment::query()->findOrFail($migration->deployment_id);
        $this->assertSame([$version->id, $this->zeroDay->digest, 'runr', ['min_scale' => 1]],
            [$deployment->version_id, $deployment->runtime_digest, $deployment->deployed_by, $deployment->deploy_config]);
        $this->assertNull($project->fresh()->runtime_patch_pin, 'a pin gives way at the deadline');
        $this->assertSame(1, AuditEvent::query()->where('action', 'runtime_patch.migrate')->count());
    }

    public function test_nothing_moves_before_the_deadline_with_the_policy_off_or_for_projects_on_the_patch(): void
    {
        $this->zeroDay->forceFill(['deadline_extended_to' => now()->addDay()])->save();
        [$project] = $this->liveProject();
        $this->enforce();
        $this->assertSame(0, RuntimePatchMigration::query()->count(), 'extended deadline');

        $this->zeroDay->forceFill(['deadline_extended_to' => null])->save();
        RuntimePatchFeed::current()->forceFill(['auto_migrate' => false])->save();
        $this->enforce();
        $this->assertSame(0, RuntimePatchMigration::query()->count(), 'policy off');
        app(RuntimePatches::class)->flush();
        $this->assertSame('overdue', app(RuntimePatches::class)->state($project->fresh())['state']);

        RuntimePatchFeed::current()->forceFill(['auto_migrate' => null])->save();
        Deployment::query()->update(['status' => 'shut_down']);
        $this->liveOn($project, Version::query()->firstOrFail(), $this->zeroDay);
        $this->enforce();
        $this->assertSame(0, RuntimePatchMigration::query()->count(), 'already on the patch');
    }

    public function test_a_revision_that_becomes_ready_finishes_the_migration_and_tells_the_owner(): void
    {
        [$project] = $this->liveProject();
        $this->enforce();
        $migration = RuntimePatchMigration::query()->sole();
        $deployment = Deployment::query()->findOrFail($migration->deployment_id);

        $deployment->transition(['pending'], ['status' => 'active', 'ready_at' => now()]);

        $this->assertSame(RuntimePatchMigration::SUCCEEDED, $migration->fresh()->status);
        $notice = Notification::query()->where('user_id', $this->owner->id)->where('data->event', 'migrated')->first();
        $this->assertNotNull($notice);
    }

    public function test_a_revision_that_fails_is_rolled_back_to_the_previous_digest_and_pinned_there(): void
    {
        [$project, $version] = $this->liveProject();
        $this->enforce();
        $migration = RuntimePatchMigration::query()->sole();
        $failed = Deployment::query()->findOrFail($migration->deployment_id);

        // what CheckDeploymentReadyJob does when the revision does not become ready
        $failed->transition(['pending'], ['status' => 'failed', 'knative_message' => 'Readiness timeout after 300s: CrashLoopBackOff']);

        $migration->refresh();
        $this->assertSame(RuntimePatchMigration::ROLLED_BACK, $migration->status);
        $this->assertStringContainsString('CrashLoopBackOff', (string) $migration->message);
        $rollback = Deployment::query()->findOrFail($migration->rollback_deployment_id);
        $this->assertSame([$version->id, $this->running->digest, $this->running->id, 'runr', ['min_scale' => 1]],
            [$rollback->version_id, $rollback->runtime_digest, $rollback->runtime_patch_id, $rollback->deployed_by, $rollback->deploy_config]);
        $this->assertSame([$this->running->id, 'runr'], [$project->fresh()->runtime_patch_pin, $project->fresh()->runtime_patch_pinned_by]);

        $notice = Notification::query()->where('user_id', $this->owner->id)->where('data->event', 'migration_failed')->firstOrFail();
        $this->assertSame('critical', $notice->level);
        $this->assertSame(1, AuditEvent::query()->where('action', 'runtime_patch.rollback')->where('result', 'success')->count());

        // the next run does not try the same patch again
        $this->enforce();
        $this->assertSame(1, RuntimePatchMigration::query()->count());
    }

    public function test_a_failed_apply_a_user_started_is_not_rolled_back(): void
    {
        [$project] = $this->liveProject();
        $migration = app(RuntimePatchMigrations::class)->start($project, null, RuntimePatchMigration::APPLY, 'dev');

        Deployment::query()->findOrFail($migration->deployment_id)->transition(['pending'], ['status' => 'failed', 'knative_message' => 'boom']);

        $this->assertSame(RuntimePatchMigration::FAILED, $migration->fresh()->status);
        $this->assertSame(2, Deployment::query()->count(), 'no rollback deployment');
    }

    public function test_the_watch_catches_what_the_hook_missed_and_times_out(): void
    {
        [$project] = $this->liveProject();
        $this->enforce();
        $migration = RuntimePatchMigration::query()->sole();
        // a status written without model events (the hook does not see it)
        Deployment::query()->whereKey($migration->deployment_id)->update(['status' => 'active', 'ready_at' => now()]);

        $this->enforce();
        $this->assertSame(RuntimePatchMigration::SUCCEEDED, $migration->fresh()->status);

        $stuck = RuntimePatchMigration::query()->create([
            'project_id' => $project->id, 'patch_id' => $this->zeroDay->id, 'trigger' => RuntimePatchMigration::APPLY,
            'status' => RuntimePatchMigration::STARTED, 'deployment_id' => Deployment::query()->create([
                'project_id' => $project->id, 'version_id' => Version::query()->value('id'), 'status' => 'pending', 'deployed_by' => 'dev',
            ])->id,
        ]);
        $stuck->forceFill(['created_at' => now()->subHours(2)])->save();

        $this->enforce();
        $this->assertSame(RuntimePatchMigration::FAILED, $stuck->fresh()->status);
    }

    public function test_a_prebuilt_version_is_built_again_from_the_patch(): void
    {
        Bus::fake([BuildVersionJob::class]);
        [$project, $version] = $this->liveProject('10.43.0.5:5000/runr-builds/demo:v1');

        $this->enforce();

        $migration = RuntimePatchMigration::query()->sole();
        $queued = QueuedDeploy::query()->findOrFail($migration->queued_deploy_id);
        $this->assertSame([QueuedDeploys::REASON_RUNTIME_PATCH, $version->id], [$queued->reason, $queued->version_id]);
        $this->assertSame($this->zeroDay->digest, $version->fresh()->runtime_base_pin);
        $this->assertSame('pending', $version->fresh()->build_status);
        Bus::assertDispatched(BuildVersionJob::class);

        // the build fails: the version is built again on the digest it ran before
        $queued->forceFill(['status' => QueuedDeploy::FAILED, 'message' => 'v1 could not be built'])->save();
        Version::query()->whereKey($version->id)->update(['build_status' => 'ready']);
        app(RuntimePatchMigrations::class)->check($migration);

        $this->assertSame(RuntimePatchMigration::ROLLED_BACK, $migration->fresh()->status);
        $this->assertSame($this->running->digest, $version->fresh()->runtime_base_pin);
    }
}
