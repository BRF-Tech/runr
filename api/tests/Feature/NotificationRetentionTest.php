<?php

namespace Tests\Feature;

use App\Jobs\PruneNotificationsJob;
use App\Models\Notification;
use App\Models\NotificationUserState;
use App\Models\User;
use App\Services\Notifications\NotificationRetention;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Notification retention (notification-retention, daily): notifications created more than
 * notifications.retention_days ago (90 by default, 0 keeps them) are deleted for good — a user's
 * own and the shared ones, read or not, soft-deleted or not — with every member's state of a
 * shared one (cascade), a chunk at a time; so are the notifications and state rows of users that
 * no longer exist. Deleting an account, by its user or by an administrator, deletes the user's
 * notifications and their state of the shared ones at once. Multi-tenant: NotificationTenancyTest.
 */
class NotificationRetentionTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    /** now: 2026-11-04 12:00, the cutoff of the 90-day retention 2026-08-06 12:00 */
    private const NOW = '2026-11-04 12:00:00';

    private User $ada;

    private User $grace;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        $this->ada = $this->userWithRole('developer', ['password' => Hash::make('ada password')]);
        $this->grace = $this->userWithRole('developer');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notify(?User $user, string $title, string $at, bool $read = false, bool $trashed = false): string
    {
        $notification = Notification::create([
            'user_id' => $user?->id, 'type' => $user ? 'model_service.deploy_failed' : 'resource_threshold',
            'level' => 'warning', 'title' => $title, 'read_at' => $read ? Carbon::parse($at) : null,
        ]);
        $notification->forceFill(['created_at' => Carbon::parse($at), 'updated_at' => Carbon::parse($at)])->save();
        if ($trashed) {
            $notification->delete();
        }

        return $notification->id;
    }

    /** $user's read (and deleted) state of the shared notification $id */
    private function state(string $id, User $user, bool $deleted = false): void
    {
        NotificationUserState::query()->create([
            'notification_id' => $id, 'user_id' => $user->id, 'read_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
    }

    /** @return list<string> titles of every notification row, soft-deleted ones too */
    private function titles(): array
    {
        return Notification::withTrashed()->orderBy('title')->pluck('title')->all();
    }

    public function test_the_retention_runs_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === 'notification-retention');

        $this->assertNotNull($event, 'scheduled task notification-retention');
        $this->assertSame('45 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(90, config('notifications.retention_days'), 'RUNR_NOTIFICATION_RETENTION_DAYS defaults to 90');
    }

    public function test_notifications_past_the_retention_are_deleted_for_good_with_their_state(): void
    {
        $this->notify($this->ada, 'expired own unread', '2026-08-06 11:00:00');
        $this->notify($this->ada, 'expired own read', '2026-07-01 10:00:00', read: true);
        $this->notify($this->ada, 'expired own deleted', '2026-05-01 10:00:00', trashed: true);
        $expiredShared = $this->notify(null, 'expired shared', '2026-08-01 10:00:00');
        $this->state($expiredShared, $this->ada);
        $this->state($expiredShared, $this->grace, deleted: true);
        $this->notify(null, 'expired shared deleted before 0.6.5', '2026-06-01 10:00:00', read: true, trashed: true);
        $keptShared = $this->notify(null, 'kept shared', '2026-08-06 13:00:00');
        $this->state($keptShared, $this->grace);
        $this->notify($this->grace, 'kept own deleted', '2026-11-01 10:00:00', trashed: true);
        $this->notify($this->ada, 'kept own', '2026-11-04 09:00:00');

        (new PruneNotificationsJob)->handle(app(NotificationRetention::class));

        $this->assertSame(['kept own', 'kept own deleted', 'kept shared'], $this->titles());
        $this->assertSame([$keptShared], NotificationUserState::query()->pluck('notification_id')->all(), 'the state rows go with their notification');
        $this->assertSame(['kept shared' => false], collect($this->asUser($this->ada)->getJson('/api/notifications')->json('data'))
            ->whereNull('user_id')->mapWithKeys(fn (array $n) => [$n['title'] => $n['read_at'] !== null])->all());
    }

    public function test_a_retention_of_0_keeps_every_notification_and_another_value_applies_its_days(): void
    {
        $this->notify($this->ada, 'a year old', '2025-11-04 10:00:00', trashed: true);
        $this->notify(null, 'a month old', '2026-10-04 10:00:00');

        config(['notifications.retention_days' => 0]);
        $this->assertSame(['expired' => 0, 'users' => 0], app(NotificationRetention::class)->prune());
        $this->assertSame(['a month old', 'a year old'], $this->titles());

        config(['notifications.retention_days' => 7]);
        $this->assertSame(['expired' => 2, 'users' => 0], app(NotificationRetention::class)->prune());
        $this->assertSame([], $this->titles());
    }

    public function test_the_prune_deletes_more_rows_than_one_chunk(): void
    {
        $rows = [];
        foreach (range(1, NotificationRetention::CHUNK * 2 + 5) as $i) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()), 'user_id' => $i % 2 ? null : (string) $this->ada->id,
                'type' => 'resource_threshold', 'level' => 'info', 'title' => "old {$i}",
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
                'deleted_at' => $i % 3 ? null : '2026-01-02 00:00:00',
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }
        $this->notify(null, 'recent', '2026-11-03 10:00:00');

        $this->assertSame(['expired' => NotificationRetention::CHUNK * 2 + 5, 'users' => 0], app(NotificationRetention::class)->prune());
        $this->assertSame(['recent'], $this->titles());
    }

    public function test_the_notifications_of_users_that_no_longer_exist_go_whatever_the_retention(): void
    {
        config(['notifications.retention_days' => 0]);
        $shared = $this->notify(null, 'shared', '2026-11-01 10:00:00');
        $this->state($shared, $this->ada);
        $this->state($shared, $this->grace);
        $this->notify($this->ada, 'ada own', '2026-11-01 10:00:00');
        $this->notify($this->grace, 'grace own', '2026-11-01 10:00:00', trashed: true);
        // Left behind by a deletion before 0.6.5: the account is gone, its rows are not
        DB::table('users')->where('id', $this->grace->id)->delete();

        $this->assertSame(['expired' => 0, 'users' => 2], app(NotificationRetention::class)->prune());

        $this->assertSame(['ada own', 'shared'], $this->titles());
        $this->assertSame([$this->ada->id], NotificationUserState::query()->pluck('user_id')->all());
    }

    public function test_deleting_ones_own_account_deletes_ones_notifications_and_state(): void
    {
        $shared = $this->notify(null, 'shared', '2026-11-01 10:00:00');
        $this->state($shared, $this->ada);
        $this->state($shared, $this->grace);
        $this->notify($this->ada, 'ada own', '2026-11-01 10:00:00');
        $this->notify($this->ada, 'ada own deleted', '2026-11-01 10:00:00', trashed: true);
        $this->notify($this->grace, 'grace own', '2026-11-01 10:00:00');

        $this->asUser($this->ada)->deleteJson('/api/account', ['password' => 'ada password'])->assertOk();

        $this->assertNull(User::query()->find($this->ada->id));
        $this->assertSame(['grace own', 'shared'], $this->titles(), 'the shared notification stays for the others');
        $this->assertSame([$this->grace->id], NotificationUserState::query()->pluck('user_id')->all());
    }

    public function test_an_administrator_deleting_a_user_deletes_their_notifications_and_state(): void
    {
        $owner = $this->userWithRole('owner');
        $shared = $this->notify(null, 'shared', '2026-11-01 10:00:00');
        $this->state($shared, $this->grace, deleted: true);
        $this->state($shared, $owner);
        $this->notify($this->grace, 'grace own', '2026-11-01 10:00:00', read: true);
        $this->notify($this->ada, 'ada own', '2026-11-01 10:00:00');

        $this->asUser($owner)->deleteJson("/api/users/{$this->grace->id}")->assertOk();

        $this->assertSame(['ada own', 'shared'], $this->titles());
        $this->assertSame([$owner->id], NotificationUserState::query()->pluck('user_id')->all());
    }

    private function asUser(User $user): static
    {
        return $this->signedIn($user);
    }
}
