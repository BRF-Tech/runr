<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The notification centre (/api/notifications): a user sees, counts, reads and removes their own
 * notifications and the shared ones (no user: resource thresholds) — never another user's (a
 * job result for whoever started it, a support ticket for each admin), which are pushed on that
 * user's channel only.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertNotNull($own->fresh()->read_at);
        $this->patchJson("/api/notifications/{$theirs->id}/read")->assertNotFound();
        $this->deleteJson("/api/notifications/{$theirs->id}")->assertNotFound();

        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('status', 'ok');
        $this->assertNotNull($shared->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at, 'another user\'s stays unread');

        $this->deleteJson("/api/notifications/{$own->id}")->assertOk();
        $this->assertSoftDeleted($own);

        $this->deleteJson('/api/notifications')->assertOk();
        $this->assertSoftDeleted($shared);
        $this->assertNotSoftDeleted($theirs);
        $this->assertSame(0, $this->getJson('/api/notifications')->json('total'));
        $this->asUser($this->grace)->getJson('/api/notifications')->assertJsonPath('data.*.title', ['grace: deploy failed']);
    }
}
