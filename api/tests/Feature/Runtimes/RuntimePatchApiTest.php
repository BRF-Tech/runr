<?php

namespace Tests\Feature\Runtimes;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\PlatformAdvisory;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimePatchMigration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * The runtime patch API (routes/runtime-patches.php): a project's patches, apply, pin / unpin,
 * the banners, and the platform administrators' Patches page with its actions.
 */
class RuntimePatchApiTest extends TestCase
{
    use ActsWithRoles, BuildsRuntimePatches, RefreshDatabase, RegistersRuntimes;

    private User $owner;

    private User $developer;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        // The deploy pipeline (ProcessDeployment, ProcessTeardown) is not under test: deployments stay pending
        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);

        $this->owner = $this->userWithRole('owner');
        $this->developer = $this->userWithRole('developer');
        $this->viewer = $this->userWithRole('viewer');
    }

    /** A python project live on $running, with two newer patches (the newest one zero-day). */
    private function projectBehind(?RuntimePatch &$running = null, ?RuntimePatch &$newest = null): array
    {
        $running = $this->runtimePatch(['tag' => '3.14.6-r1', 'published_at' => now()->subDays(8)]);
        $this->runtimePatch(['tag' => '3.14.7-r1', 'published_at' => now()->subDays(4)]);
        $newest = $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'published_at' => now()->subDay(), 'deadline_at' => now()->addDays(2)]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version, $running);

        return [$project, $version];
    }

    public function test_a_viewer_sees_the_patches_with_why_they_came(): void
    {
        [$project] = $this->projectBehind($running, $newest);

        $this->signedIn($this->viewer)->getJson("/api/projects/{$project->id}/runtime-patches")
            ->assertOk()
            ->assertJsonPath('data.state.state', 'patch_available')
            ->assertJsonPath('data.state.level', 'zero-day')
            ->assertJsonPath('data.patches.0.tag', '3.14.8-r1')
            ->assertJsonPath('data.patches.0.level', 'zero-day')
            ->assertJsonPath('data.patches.0.cves.0', $newest->cves[0])
            ->assertJsonPath('data.patches.0.url', $newest->url)
            ->assertJsonPath('data.keep', 5)
            ->assertJsonPath('data.auto_migrate', true)
            ->assertJsonCount(3, 'data.patches');

        $this->signedIn($this->viewer)->withHeader('Accept-Language', 'tr')->getJson("/api/projects/{$project->id}/runtime-patches")
            ->assertJsonPath('data.patches.0.reason', $newest->reason['tr']);

        $this->signedIn($this->viewer)->postJson("/api/projects/{$project->id}/runtime-patches/apply")->assertForbidden();
    }

    public function test_apply_redeploys_the_live_version_onto_the_newest_patch(): void
    {
        [$project, $version] = $this->projectBehind($running, $newest);
        $project->forceFill(['runtime_patch_pin' => $running->id])->save();

        $response = $this->signedIn($this->developer)->postJson("/api/projects/{$project->id}/runtime-patches/apply")
            ->assertStatus(202)
            ->assertJsonPath('data.patch_id', $newest->id)
            ->assertJsonPath('data.trigger', 'apply')
            ->assertJsonPath('data.status', 'started');

        $deployment = Deployment::query()->findOrFail($response->json('data.deployment_id'));
        $this->assertSame([$version->id, 'pending', $newest->digest, $newest->id, $this->developer->username],
            [$deployment->version_id, $deployment->status, $deployment->runtime_digest, $deployment->runtime_patch_id, $deployment->deployed_by]);
        $migration = RuntimePatchMigration::query()->firstOrFail();
        $this->assertSame([$running->digest, $running->id], [$migration->previous_digest, $migration->previous_patch_id]);
        $this->assertNull($project->fresh()->runtime_patch_pin, 'applying the newest patch ends the pin');
        $this->assertSame(1, AuditEvent::query()->where('action', 'runtime_patch.apply')->count());

        // a second apply while the first one runs
        $this->signedIn($this->developer)->postJson("/api/projects/{$project->id}/runtime-patches/apply")
            ->assertStatus(409)->assertJsonPath('code', 'migration_running');
    }

    public function test_apply_refusals(): void
    {
        $this->runtimePatch(['tag' => '3.14.8-r1']);
        [$idle] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->signedIn($this->developer)->postJson("/api/projects/{$idle->id}/runtime-patches/apply")
            ->assertStatus(409)->assertJsonPath('code', 'not_deployed');

        [$current, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($current, $version, RuntimePatch::query()->firstOrFail());
        $this->signedIn($this->developer)->postJson("/api/projects/{$current->id}/runtime-patches/apply")
            ->assertStatus(409)->assertJsonPath('code', 'already_running');

        [$noPatches, $v] = $this->runtimeProject('nodejs', 'http', ['index.js' => 'x']);
        $this->liveOn($noPatches, $v);
        $this->signedIn($this->developer)->postJson("/api/projects/{$noPatches->id}/runtime-patches/apply")
            ->assertStatus(422)->assertJsonPath('code', 'no_patch');
    }

    public function test_pin_to_one_of_the_last_five_and_unpin(): void
    {
        [$project] = $this->projectBehind($running, $newest);
        $middle = RuntimePatch::query()->findOrFail('python-3.14-3.14.7-r1');

        $this->signedIn($this->developer)->putJson("/api/projects/{$project->id}/runtime-patches/pin", ['patch_id' => $middle->id])
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'pinned')
            ->assertJsonPath('data.pin.id', $middle->id)
            ->assertJsonPath('migration.trigger', 'pin');
        $this->assertSame($middle->digest, Deployment::query()->latest('created_at')->latest('id')->firstOrFail()->runtime_digest);
        $this->assertSame((string) $this->developer->id, $project->fresh()->runtime_patch_pinned_by);

        // the running patch: pinned without a redeploy
        $this->signedIn($this->developer)->putJson("/api/projects/{$project->id}/runtime-patches/pin", ['patch_id' => $running->id, 'deploy' => false])
            ->assertOk()->assertJsonPath('migration', null);

        $this->signedIn($this->developer)->deleteJson("/api/projects/{$project->id}/runtime-patches/pin")
            ->assertOk()->assertJsonPath('data.pin', null);
        $this->assertNull($project->fresh()->runtime_patch_pin);
    }

    public function test_only_the_newest_five_can_be_pinned(): void
    {
        $old = $this->runtimePatch(['tag' => '3.14.1-r1', 'published_at' => now()->subDays(40)]);
        foreach (range(2, 6) as $i) {
            $this->runtimePatch(['tag' => "3.14.{$i}-r1", 'published_at' => now()->subDays(10 - $i)]);
        }
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->liveOn($project, $version);

        $this->signedIn($this->developer)->putJson("/api/projects/{$project->id}/runtime-patches/pin", ['patch_id' => $old->id])
            ->assertStatus(422)->assertJsonPath('code', 'not_pinnable');
        $this->signedIn($this->developer)->putJson("/api/projects/{$project->id}/runtime-patches/pin", [])
            ->assertStatus(422)->assertJsonValidationErrors('patch_id');
    }

    public function test_banners_show_urgent_patches_of_visible_projects_until_none_is_behind(): void
    {
        [$project, $version] = $this->projectBehind($running, $newest);

        $this->signedIn($this->owner)->getJson('/api/runtime-patches/banners')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'runtime_patch')
            ->assertJsonPath('data.0.level', 'zero-day')
            ->assertJsonPath('data.0.patch.tag', '3.14.8-r1')
            ->assertJsonPath('data.0.project_count', 1)
            ->assertJsonPath('data.0.projects.0.id', $project->id);

        // a member without access to the project sees nothing
        $outsider = $this->userWithRole('billing');
        $this->signedIn($outsider)->getJson('/api/runtime-patches/banners')->assertOk()->assertJsonCount(0, 'data');

        // moved onto the patch: the banner goes
        Deployment::query()->update(['status' => 'shut_down']);
        $this->liveOn($project, $version, $newest);
        $this->signedIn($this->owner)->getJson('/api/runtime-patches/banners')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_platform_administrators_see_releases_and_the_feed_state(): void
    {
        $admin = $this->platformAdmin();
        config(['app.version' => 'v0.5.10', 'runtime_patches.public_key' => '']);
        PlatformAdvisory::query()->create(['version' => '0.5.11', 'level' => 'zero-day', 'reason' => ['en' => 'Fixes the API'], 'url' => 'https://example.test/0.5.11', 'in_feed' => true]);
        PlatformAdvisory::query()->create(['version' => '0.5.9', 'level' => 'critical', 'reason' => ['en' => 'Old'], 'in_feed' => true]);

        $banners = collect($this->signedIn($admin)->getJson('/api/runtime-patches/banners')->assertOk()->json('data'))->keyBy('kind');

        $this->assertSame('0.5.11', $banners['platform_advisory']['version']);
        $this->assertSame('zero-day', $banners['platform_advisory']['level']);
        $this->assertSame('Fixes the API', $banners['platform_advisory']['reason']);
        $this->assertArrayHasKey('feed_disabled', $banners->all());

        $this->signedIn($this->owner)->getJson('/api/runtime-patches/banners')->assertJsonCount(0, 'data');
    }

    public function test_the_patches_page_is_for_platform_administrators(): void
    {
        // the feed off (an empty key): the page says so
        config(['runtime_patches.public_key' => '']);

        [$project] = $this->projectBehind($running, $newest);
        $admin = $this->platformAdmin();

        $this->signedIn($this->owner)->getJson('/api/admin/runtime-patches')->assertForbidden();

        $page = $this->signedIn($admin)->getJson('/api/admin/runtime-patches')->assertOk();
        $patch = collect($page->json('data.patches'))->firstWhere('id', $newest->id);
        $this->assertSame(1, $patch['affected']);
        $this->assertSame([['tenant_id' => null, 'name' => null, 'affected' => 1, 'migrations' => []]], $patch['organizations']);
        $this->assertSame('disabled', $page->json('data.feed.status'));

        $this->signedIn($admin)->putJson('/api/admin/runtime-patches/policy', ['auto_migrate' => false])
            ->assertOk()->assertJsonPath('data.auto_migrate', false);
        $this->assertFalse(RuntimePatchFeed::current()->autoMigrates());

        $until = now()->addWeeks(2)->startOfMinute();
        $this->signedIn($admin)->putJson("/api/admin/runtime-patches/{$newest->id}/deadline", ['deadline_at' => $until->toIso8601String()])
            ->assertOk()->assertJsonPath('data.deadline_extended', true);
        $this->assertTrue($newest->fresh()->deadline_extended_to->equalTo($until));
        $this->signedIn($admin)->putJson("/api/admin/runtime-patches/{$running->id}/deadline", ['deadline_at' => $until->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'no_deadline');
        $this->signedIn($admin)->putJson("/api/admin/runtime-patches/{$newest->id}/deadline", ['deadline_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422);

        $this->signedIn($admin)->postJson("/api/admin/runtime-patches/{$newest->id}/force")
            ->assertStatus(202)->assertJsonPath('data.started', 1);
        $this->assertSame(RuntimePatchMigration::FORCE, RuntimePatchMigration::query()->where('project_id', $project->id)->value('trigger'));

        $this->signedIn($admin)->postJson('/api/admin/runtime-patches/check')->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->assertSame(1, AuditEvent::query()->where('action', 'runtime_patch.force')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'runtime_patch.policy.update')->count());
    }

    public function test_the_catalog_of_patches(): void
    {
        $this->runtimePatch(['tag' => '3.14.8-r1']);
        $this->runtimePatch(['tag' => '3.14.9-r1', 'in_feed' => false]);

        $this->signedIn($this->viewer)->getJson('/api/runtime-patches?runtime=python')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tag', '3.14.8-r1')
            ->assertJsonPath('keep', 5);
    }
}
