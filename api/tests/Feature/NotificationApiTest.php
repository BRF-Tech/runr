<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationUserState;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The notification centre (/api/notifications): a user sees, counts, reads and removes their own
 * notifications and the shared ones (no user: resource thresholds) — never another user's (a
 * job result for whoever started it, a support ticket for each admin), which are pushed on that
 * user's channel only. Reading and removing are per user: a shared notification one member reads
 * or deletes stays unread and listed for the others (notification_user_states).
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_04_100001_create_notification_user_states_table.php';

    private User $ada;

    private User $grace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = User::factory()->create();
        $this->grace = User::factory()->create();
    }

    private function notify(?User $user, string $title, ?string $readAt = null, string $at = '2026-10-15 10:00:00'): Notification
    {
        $notification = Notification::create([
            'user_id' => $user?->id, 'type' => $user ? 'model_service.deploy_failed' : 'resource_threshold',
            'level' => 'warning', 'title' => $title, 'read_at' => $readAt,
        ]);
        $notification->forceFill(['created_at' => Carbon::parse($at)])->save();

        return $notification;
    }

    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    /**
     * What $user sees: title => read (true / false), in the list's order, and the unread count.
     *
     * @return array{list: array<string, bool>, unread: int}
     */
    private function viewOf(User $user): array
    {
        $this->asUser($user);
        $list = collect($this->getJson('/api/notifications?per_page=100')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $n) => [$n['title'] => $n['read_at'] !== null])
            ->all();

        return ['list' => $list, 'unread' => $this->getJson('/api/notifications/unread-count')->assertOk()->json('count')];
    }

    public function test_a_user_sees_their_own_and_the_shared_notifications(): void
    {
        $this->notify($this->ada, 'ada: deploy failed', at: '2026-10-15 10:00:00');
        $this->notify(null, 'eu: CPU 91%', at: '2026-10-15 11:00:00');
        $this->notify($this->ada, 'ada: build done', '2026-10-15 09:30:00', '2026-10-15 09:00:00');
        $this->notify($this->grace, 'grace: deploy failed', at: '2026-10-15 12:00:00');

        $this->asUser($this->ada)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.*.title', ['eu: CPU 91%', 'ada: deploy failed', 'ada: build done']);
        $this->getJson('/api/notifications?unread_only=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.title', 'eu: CPU 91%');
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('count', 2);
        $this->assertArrayNotHasKey('user_read_at', $this->getJson('/api/notifications')->json('data.0'));

        $this->asUser($this->grace)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['grace: deploy failed', 'eu: CPU 91%']);
    }

    public function test_reading_and_removing_stay_within_the_users_notifications(): void
    {
        $own = $this->notify($this->ada, 'ada: deploy failed');
        $shared = $this->notify(null, 'eu: CPU 91%');
        $theirs = $this->notify($this->grace, 'grace: deploy failed');
        $this->asUser($this->ada);

        $this->patchJson("/api/notifications/{$own->id}/read")->assertOk()->assertJsonPath('id', $own->id);
        $this->assertNotNull($own->fresh()->read_at, 'an own notification keeps its state in its row');
        $this->patchJson("/api/notifications/{$theirs->id}/read")->assertNotFound();
        $this->deleteJson("/api/notifications/{$theirs->id}")->assertNotFound();

        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('status', 'ok');
        $this->assertNull($shared->fresh()->read_at, 'the shared row is the same for everyone');
        $this->assertNotNull(NotificationUserState::query()->where(['notification_id' => $shared->id, 'user_id' => $this->ada->id])->value('read_at'));
        $this->assertNull($theirs->fresh()->read_at, 'another user\'s stays unread');

        $this->deleteJson("/api/notifications/{$own->id}")->assertOk();
        $this->assertSoftDeleted($own);

        $this->deleteJson('/api/notifications')->assertOk();
        $this->assertNotSoftDeleted($shared);
        $this->assertNotSoftDeleted($theirs);
        $this->assertSame(0, $this->getJson('/api/notifications')->json('total'));
        $this->assertSame(0, $this->getJson('/api/notifications/unread-count')->json('count'));
        // assertEquals: both were created at the same time, in either order
        $this->assertEquals(['list' => ['grace: deploy failed' => false, 'eu: CPU 91%' => false], 'unread' => 2], $this->viewOf($this->grace));
    }

    public function test_marking_a_shared_notification_read_is_one_members_own(): void
    {
        $cpu = $this->notify(null, 'eu: CPU 91%', at: '2026-10-15 11:00:00');
        $this->notify(null, 'eu: memory 85%', at: '2026-10-15 10:00:00');
        $this->notify($this->grace, 'grace: build done', at: '2026-10-15 09:00:00');

        $this->asUser($this->ada)->patchJson("/api/notifications/{$cpu->id}/read")
            ->assertOk()
            ->assertJsonPath('id', $cpu->id)
            ->assertJsonPath('user_id', null);
        $readAt = $this->patchJson("/api/notifications/{$cpu->id}/read")->assertOk()->json('read_at');
        $this->assertNotNull($readAt, 'the answer carries ada\'s read time');

        $this->assertSame(['list' => ['eu: CPU 91%' => true, 'eu: memory 85%' => false], 'unread' => 1], $this->viewOf($this->ada));
        $this->asUser($this->ada)->getJson('/api/notifications?unread_only=1')->assertJsonPath('data.*.title', ['eu: memory 85%']);
        $this->assertSame(
            ['list' => ['eu: CPU 91%' => false, 'eu: memory 85%' => false, 'grace: build done' => false], 'unread' => 3],
            $this->viewOf($this->grace),
        );
        $this->asUser($this->grace)->getJson('/api/notifications?unread_only=1')->assertJsonPath('total', 3);

        // Grace reads it too: her own row, ada's read time stays
        $this->asUser($this->grace)->patchJson("/api/notifications/{$cpu->id}/read")->assertOk();
        $this->assertSame(2, NotificationUserState::query()->where('notification_id', $cpu->id)->count());
        $this->assertSame(1, $this->viewOf($this->ada)['unread']);
        $this->assertSame(2, $this->viewOf($this->grace)['unread']);
        $this->assertNull($cpu->fresh()->read_at);
    }

    public function test_mark_all_as_read_by_one_member_leaves_the_others_unread(): void
    {
        $this->notify(null, 'eu: CPU 91%', at: '2026-10-15 11:00:00');
        $this->notify(null, 'eu: memory 85%', at: '2026-10-15 10:00:00');
        $this->notify($this->ada, 'ada: deploy failed', at: '2026-10-15 09:00:00');
        $this->notify($this->grace, 'grace: build done', at: '2026-10-15 08:00:00');

        $this->asUser($this->ada)->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(
            ['list' => ['eu: CPU 91%' => true, 'eu: memory 85%' => true, 'ada: deploy failed' => true], 'unread' => 0],
            $this->viewOf($this->ada),
        );
        $this->assertSame(
            ['list' => ['eu: CPU 91%' => false, 'eu: memory 85%' => false, 'grace: build done' => false], 'unread' => 3],
            $this->viewOf($this->grace),
        );

        // A shared notification after it is unread for ada again; a second read-all writes nothing twice
        $this->notify(null, 'eu: CPU 95%', at: '2026-10-15 12:00:00');
        $this->assertSame(1, $this->viewOf($this->ada)['unread']);
        $this->asUser($this->ada)->postJson('/api/notifications/read-all')->assertOk();
        $this->asUser($this->ada)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->viewOf($this->ada)['unread']);
        $this->assertSame(4, $this->viewOf($this->grace)['unread']);
        $this->assertSame(3, NotificationUserState::query()->where('user_id', $this->ada->id)->count());
        $this->assertSame(0, NotificationUserState::query()->where('user_id', $this->grace->id)->count());
    }

    public function test_deleting_a_shared_notification_removes_it_from_one_members_list(): void
    {
        $cpu = $this->notify(null, 'eu: CPU 91%', at: '2026-10-15 11:00:00');
        $this->notify(null, 'eu: memory 85%', at: '2026-10-15 10:00:00');

        $this->asUser($this->ada)->deleteJson("/api/notifications/{$cpu->id}")->assertOk()->assertJsonPath('status', 'ok');

        $this->assertNotSoftDeleted($cpu);
        $this->assertSame(['list' => ['eu: memory 85%' => false], 'unread' => 1], $this->viewOf($this->ada));
        $this->assertSame(['list' => ['eu: CPU 91%' => false, 'eu: memory 85%' => false], 'unread' => 2], $this->viewOf($this->grace));

        // Gone for ada: neither read nor deleted again; grace still can
        $this->asUser($this->ada)->patchJson("/api/notifications/{$cpu->id}/read")->assertNotFound();
        $this->deleteJson("/api/notifications/{$cpu->id}")->assertNotFound();
        $this->asUser($this->grace)->patchJson("/api/notifications/{$cpu->id}/read")->assertOk();
        $this->assertSame(['list' => ['eu: CPU 91%' => true, 'eu: memory 85%' => false], 'unread' => 1], $this->viewOf($this->grace));

        // A shared notification grace read and then deletes keeps her read time in the same row
        $this->asUser($this->grace)->deleteJson("/api/notifications/{$cpu->id}")->assertOk();
        $state = NotificationUserState::query()->where(['notification_id' => $cpu->id, 'user_id' => $this->grace->id])->firstOrFail();
        $this->assertNotNull($state->read_at);
        $this->assertNotNull($state->deleted_at);
    }

    public function test_delete_all_by_one_member_leaves_the_others_list(): void
    {
        $this->notify(null, 'eu: CPU 91%', at: '2026-10-15 11:00:00');
        $memory = $this->notify(null, 'eu: memory 85%', at: '2026-10-15 10:00:00');
        $adas = $this->notify($this->ada, 'ada: deploy failed', at: '2026-10-15 09:00:00');
        $this->notify($this->grace, 'grace: build done', at: '2026-10-15 08:00:00');
        $this->asUser($this->ada)->patchJson("/api/notifications/{$memory->id}/read")->assertOk();

        $this->asUser($this->ada)->deleteJson('/api/notifications')->assertOk();

        $this->assertSoftDeleted($adas);
        $this->assertSame(['list' => [], 'unread' => 0], $this->viewOf($this->ada));
        $this->assertSame(
            ['list' => ['eu: CPU 91%' => false, 'eu: memory 85%' => false, 'grace: build done' => false], 'unread' => 3],
            $this->viewOf($this->grace),
        );

        // Only what ada deleted is gone for her: a later shared notification shows up
        $this->notify(null, 'eu: CPU 95%', at: '2026-10-15 12:00:00');
        $this->assertSame(['list' => ['eu: CPU 95%' => false], 'unread' => 1], $this->viewOf($this->ada));

        // Grace's delete-all: her own soft deleted, the shared gone for her only
        $this->asUser($this->grace)->deleteJson('/api/notifications')->assertOk();
        $this->assertSame(['list' => [], 'unread' => 0], $this->viewOf($this->grace));
        $this->assertSame(['list' => ['eu: CPU 95%' => false], 'unread' => 1], $this->viewOf($this->ada));
        $this->assertSame(0, Notification::query()->whereNull('user_id')->onlyTrashed()->count());
    }

    public function test_a_shared_notification_read_or_deleted_before_the_upgrade_stays_so_for_everyone(): void
    {
        // 0.6.4 and earlier wrote one read_at / soft delete for every member
        $this->notify(null, 'eu: CPU 91%', '2026-10-15 11:30:00', '2026-10-15 11:00:00');
        $this->notify(null, 'eu: memory 85%', at: '2026-10-15 10:00:00')->delete();
        $this->notify(null, 'eu: disk 70%', at: '2026-10-15 09:00:00');

        foreach ([$this->ada, $this->grace] as $user) {
            $this->assertSame(['list' => ['eu: CPU 91%' => true, 'eu: disk 70%' => false], 'unread' => 1], $this->viewOf($user));
        }
        $this->asUser($this->ada)->getJson('/api/notifications?unread_only=1')->assertJsonPath('data.*.title', ['eu: disk 70%']);

        // read-all and delete-all leave the rows as they are and write only what ada had not yet
        $this->asUser($this->ada)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(1, NotificationUserState::query()->where('user_id', $this->ada->id)->count(), 'disk only: CPU was read already');
        $this->asUser($this->ada)->deleteJson('/api/notifications')->assertOk();
        $this->assertSame(2, NotificationUserState::query()->where('user_id', $this->ada->id)->whereNotNull('deleted_at')->count());
        $this->assertSame(['list' => ['eu: CPU 91%' => true, 'eu: disk 70%' => false], 'unread' => 1], $this->viewOf($this->grace));
    }

    public function test_mark_all_as_read_and_delete_all_cover_more_shared_notifications_than_one_chunk(): void
    {
        $rows = [];
        for ($i = 0; $i < 1203; $i++) {
            $rows[] = ['id' => strtolower((string) Str::ulid()), 'type' => 'resource_threshold', 'level' => 'warning',
                'title' => "eu: CPU {$i}", 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        $this->asUser($this->ada)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->getJson('/api/notifications/unread-count')->json('count'));
        $this->asUser($this->grace)->getJson('/api/notifications/unread-count')->assertJsonPath('count', 1203);

        $this->asUser($this->ada)->deleteJson('/api/notifications')->assertOk();
        $this->assertSame(0, $this->getJson('/api/notifications')->json('total'));
        $this->assertSame(1203, NotificationUserState::query()->where('user_id', $this->ada->id)->whereNotNull('read_at')->whereNotNull('deleted_at')->count());
        $this->asUser($this->grace)->getJson('/api/notifications')->assertJsonPath('total', 1203);
    }

    public function test_the_members_state_goes_with_its_notification(): void
    {
        $cpu = $this->notify(null, 'eu: CPU 91%');
        $memory = $this->notify(null, 'eu: memory 85%');
        $disk = $this->notify(null, 'eu: disk 70%');
        foreach ([$this->ada, $this->grace] as $user) {
            $this->asUser($user)->postJson('/api/notifications/read-all')->assertOk();
        }
        $this->asUser($this->ada)->deleteJson("/api/notifications/{$memory->id}")->assertOk();
        $this->assertSame(6, NotificationUserState::query()->count());

        // A removed notification (the model, or the rows as TenantCentralTables deletes an
        // organization's) takes every member's state along
        $cpu->forceDelete();
        $this->assertSame(0, NotificationUserState::query()->where('notification_id', $cpu->id)->count());
        DB::table('notifications')->where('id', $memory->id)->delete();
        $this->assertSame(0, NotificationUserState::query()->where('notification_id', $memory->id)->count());
        $this->assertSame(2, NotificationUserState::query()->where('notification_id', $disk->id)->count());
        $this->assertSame(['list' => ['eu: disk 70%' => true], 'unread' => 0], $this->viewOf($this->grace));
    }

    public function test_the_migration_adds_the_table_and_takes_it_away_again(): void
    {
        $migration = require base_path(self::MIGRATION);
        $shared = $this->notify(null, 'eu: CPU 91%');
        $this->asUser($this->ada)->postJson('/api/notifications/read-all')->assertOk();

        $migration->down();
        $this->assertFalse(Schema::hasTable('notification_user_states'));
        $this->assertNotNull(Notification::query()->find($shared->id), 'down drops the state table only');

        $migration->up();
        $migration->up();   // again: nothing to do
        $this->assertTrue(Schema::hasColumns('notification_user_states', ['id', 'notification_id', 'user_id', 'read_at', 'deleted_at', 'created_at', 'updated_at']));
        $this->assertSame(['list' => ['eu: CPU 91%' => false], 'unread' => 1], $this->viewOf($this->ada), 'the notification stays, ada\'s read went with the table');

        $this->asUser($this->ada)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->viewOf($this->ada)['unread']);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('notification_user_states')->insert(['notification_id' => $shared->id, 'user_id' => $this->ada->id]);
    }
}
