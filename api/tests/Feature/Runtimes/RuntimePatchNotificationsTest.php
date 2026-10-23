<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\DeliverAlertJob;
use App\Mail\RuntimePatchMail;
use App\Models\Deployment;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\User;
use App\Models\Version;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Who hears about a runtime patch, by its level (RuntimePatchNotifier, docs/runtimes.md#patch-notifications):
 * routine nobody; security in-app to the project owner, the last deployer and the organization's
 * owners and admins; critical also the platform administrators and by mail; zero-day ignores the
 * preferences and reaches the organization's alert channels. Once per patch and user.
 */
class RuntimePatchNotificationsTest extends TestCase
{
    use ActsWithRoles, BuildsRuntimePatches, RefreshDatabase, RegistersRuntimes;

    private User $orgOwner;

    private User $orgAdmin;

    private User $projectOwner;

    private User $deployer;

    private User $bystander;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        Mail::fake();
        Bus::fake([DeliverAlertJob::class]);

        $this->orgOwner = $this->userWithRole('owner');
        $this->orgAdmin = $this->userWithRole('admin');
        $this->projectOwner = $this->userWithRole('developer');
        $this->deployer = $this->userWithRole('developer');
        $this->bystander = $this->userWithRole('developer');

        $running = $this->runtimePatch(['tag' => '3.14.6-r1', 'published_at' => now()->subDays(9)]);
        [$this->project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->project->forceFill(['owner_id' => $this->projectOwner->id])->save();
        $this->liveOn($this->project, $version, $running, now()->subDays(8), ['deployed_by' => $this->deployer->username]);
    }

    /** @return list<string> ids of the users with a runtime_patch notification */
    private function told(): array
    {
        return Notification::query()->where('type', 'runtime_patch')->pluck('user_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    private function ids(User ...$users): array
    {
        return collect($users)->map(fn (User $u) => (string) $u->id)->sort()->values()->all();
    }

    public function test_routine_patches_only_show_a_badge(): void
    {
        $patch = $this->runtimePatch(['tag' => '3.14.7-r1', 'level' => RuntimePatch::ROUTINE]);

        $this->assertSame(0, app(RuntimePatchNotifier::class)->announce($patch));

        $this->assertSame([], $this->told());
        $this->assertNotNull($patch->fresh()->announced_at);
    }

    public function test_security_patches_reach_the_project_and_the_organization_in_app(): void
    {
        $patch = $this->runtimePatch(['tag' => '3.14.7-r1', 'level' => RuntimePatch::SECURITY]);

        app(RuntimePatchNotifier::class)->announce($patch);

        $this->assertSame($this->ids($this->orgOwner, $this->orgAdmin, $this->projectOwner, $this->deployer), $this->told());
        Mail::assertNothingQueued();

        $notice = Notification::query()->where('user_id', $this->projectOwner->id)->firstOrFail();
        $this->assertSame('warning', $notice->level);
        $this->assertSame($patch->id, $notice->data['patch_id']);
        $this->assertSame([['id' => $this->project->id, 'name' => $this->project->name]], $notice->data['projects']);
        $this->assertSame($patch->reason, $notice->data['reason']);
        $this->assertSame("/projects/{$this->project->id}", $notice->data['path']);
        $this->assertStringContainsString($patch->reasonText('en'), $notice->body);
    }

    public function test_critical_patches_add_the_platform_administrators_and_mail(): void
    {
        $platform = $this->platformAdmin();
        $patch = $this->runtimePatch(['tag' => '3.14.7-r1', 'level' => RuntimePatch::CRITICAL, 'deadline_at' => now()->addDays(5)]);

        app(RuntimePatchNotifier::class)->announce($patch);

        $this->assertContains((string) $platform->id, $this->told());
        $admin = Notification::query()->where('user_id', $platform->id)->firstOrFail();
        $this->assertSame('critical', $admin->level);
        $this->assertStringContainsString('1 project', $admin->title);
        Mail::assertQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->projectOwner->email));
        Mail::assertQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($platform->email));
        Mail::assertNotQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->bystander->email));
        Bus::assertNotDispatched(DeliverAlertJob::class);
    }

    public function test_preferences_turn_critical_notices_off_but_not_zero_day_ones(): void
    {
        foreach (['in_app', 'email'] as $channel) {
            NotificationPreference::query()->create(['user_id' => $this->projectOwner->id, 'type' => 'runtime_patch', 'channel' => $channel, 'enabled' => false]);
        }

        app(RuntimePatchNotifier::class)->announce($this->runtimePatch(['tag' => '3.14.7-r1', 'level' => RuntimePatch::CRITICAL, 'deadline_at' => now()->addDays(5)]));
        $this->assertNotContains((string) $this->projectOwner->id, $this->told());
        Mail::assertNotQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->projectOwner->email));

        app(RuntimePatchNotifier::class)->announce($this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'deadline_at' => now()->addDays(2)]));
        $this->assertContains((string) $this->projectOwner->id, $this->told());
        Mail::assertQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->projectOwner->email));
    }

    public function test_zero_day_patches_reach_the_alert_channels_once(): void
    {
        $slack = AlertChannel::query()->create(['name' => 'ops', 'type' => 'slack', 'config' => ['webhook_url' => 'https://hooks.example.test/x'], 'is_enabled' => true]);
        AlertChannel::query()->create(['name' => 'off', 'type' => 'webhook', 'config' => ['url' => 'https://example.test'], 'is_enabled' => false]);
        $patch = $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'deadline_at' => now()->addDays(2)]);

        app(RuntimePatchNotifier::class)->announce($patch);
        app(RuntimePatchNotifier::class)->announce($patch);

        $event = AlertEvent::query()->where('fingerprint', 'runtime-patch:'.$patch->id)->sole();
        $this->assertSame(['critical', 'firing', 'runtime_patch'], [$event->severity, $event->status, $event->type]);
        $this->assertSame([$slack->id], AlertDelivery::query()->pluck('alert_channel_id')->all());
        Bus::assertDispatchedTimes(DeliverAlertJob::class, 1);
        // and every user once
        $this->assertSame(count(array_unique($this->told())), count($this->told()));
    }

    public function test_once_per_patch_and_user_unless_reminded(): void
    {
        $patch = $this->runtimePatch(['tag' => '3.14.7-r1', 'level' => RuntimePatch::SECURITY]);
        $notifier = app(RuntimePatchNotifier::class);

        $notifier->announce($patch);
        $notifier->announce($patch);
        $this->assertCount(4, $this->told());

        $this->travel(1)->seconds();
        $this->assertSame(4, $notifier->announce($patch, remind: true));
        $this->assertCount(8, $this->told());
    }

    public function test_projects_already_on_the_patch_are_not_told(): void
    {
        $patch = $this->runtimePatch(['tag' => '3.14.7-r1', 'level' => RuntimePatch::SECURITY]);
        Deployment::query()->update(['status' => 'shut_down']);
        $this->liveOn($this->project, Version::query()->firstOrFail(), $patch);

        app(RuntimePatchNotifier::class)->announce($patch);

        $this->assertSame([], $this->told());
    }

    public function test_resolved_alerts_go_out_when_no_project_is_behind(): void
    {
        $slack = AlertChannel::query()->create(['name' => 'ops', 'type' => 'slack', 'config' => ['webhook_url' => 'https://hooks.example.test/x'], 'is_enabled' => true]);
        $patch = $this->runtimePatch(['tag' => '3.14.8-r1', 'level' => RuntimePatch::ZERO_DAY, 'deadline_at' => now()->addDays(2)]);
        $notifier = app(RuntimePatchNotifier::class);
        $notifier->announce($patch);

        $notifier->resolveAlerts($patch);
        $this->assertSame(AlertEvent::FIRING, AlertEvent::query()->sole()->status, 'still behind');

        Deployment::query()->update(['status' => 'shut_down']);
        $this->liveOn($this->project, Version::query()->firstOrFail(), $patch);
        $notifier->resolveAlerts($patch);

        $this->assertSame(AlertEvent::RESOLVED, AlertEvent::query()->sole()->status);
        $this->assertSame(1, AlertDelivery::query()->where('alert_channel_id', $slack->id)->where('kind', AlertDelivery::KIND_RESOLVE)->count());
    }
}
