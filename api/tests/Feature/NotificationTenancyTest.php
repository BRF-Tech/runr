<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\NotificationController;
use App\Jobs\PruneNotificationsJob;
use App\Models\Notification;
use App\Models\NotificationUserState;
use App\Models\User;
use App\Services\Notifications\NotificationRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Tenancy\Http\Controllers\TenancyNotificationController;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantCentralTables;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Multi-tenant: an organization's notifications and its members' read and deleted state of the
 * shared ones live in its schema (TenantMigrations 0007 and 0110), so one member's mark all as
 * read or delete all changes neither another member's view nor another organization's.
 * TenancyNotificationController keeps resource thresholds to the platform administrators.
 * Runs on SQLite and on PostgreSQL (DB_CONNECTION=pgsql).
 */
class NotificationTenancyTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const SCHEMA = [
        'Modules/Tenancy/Database/TenantMigrations/0007_add_user_id_to_notifications.php',
        'Modules/Tenancy/Database/TenantMigrations/0110_create_notification_user_states_table.php',
    ];

    private Tenant $acme;

    private Tenant $globex;

    private User $ada;

    private User $grace;

    private User $alan;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        // TenancyServiceProvider's binding in a multi-tenant install
        $this->app->bind(NotificationController::class, TenancyNotificationController::class);

        $this->acme = $this->createTenantWithSchema('acme', self::SCHEMA);
        $this->globex = $this->createTenantWithSchema('globex', self::SCHEMA);
        $this->ada = $this->member($this->acme);
        $this->grace = $this->member($this->acme);
        $this->alan = $this->member($this->globex);
        $this->root = User::factory()->create(['username' => 'root'.Str::lower(Str::random(6))]);
        $this->root->forceFill(['is_admin' => true])->save();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function member(Tenant $tenant): User
    {
        $user = User::factory()->create(['username' => 'u'.Str::lower(Str::random(10))]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'developer',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function notify(Tenant $tenant, string $title, string $type = 'region.migrated'): string
    {
        return $tenant->run(fn () => Notification::create(['type' => $type, 'level' => 'warning', 'title' => $title])->id);
    }

    /**
     * What $user sees in $tenant: title => read, and the unread count.
     *
     * @return array{list: array<string, bool>, unread: int}
     */
    private function viewOf(Tenant $tenant, User $user): array
    {
        tenancy()->initialize($tenant);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'sanctum');
        $list = collect($this->getJson('/api/notifications')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $n) => [$n['title'] => $n['read_at'] !== null])
            ->sortKeys()
            ->all();

        return ['list' => $list, 'unread' => $this->getJson('/api/notifications/unread-count')->assertOk()->json('count')];
    }

    private function as(Tenant $tenant, User $user): static
    {
        tenancy()->initialize($tenant);
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    /** @return array<string, int> user id => state rows in $tenant's schema */
    private function statesIn(Tenant $tenant): array
    {
        return $tenant->run(fn () => NotificationUserState::query()->get()->countBy('user_id')->all());
    }

    public function test_one_members_actions_change_neither_another_members_view_nor_another_organizations(): void
    {
        $moved = $this->notify($this->acme, 'eu moved');
        $this->notify($this->acme, 'eu: CPU 91%', 'resource_threshold');
        $this->notify($this->globex, 'us moved');

        $this->as($this->acme, $this->ada)->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(['list' => ['eu moved' => true], 'unread' => 0], $this->viewOf($this->acme, $this->ada));
        $this->assertSame(['list' => ['eu moved' => false], 'unread' => 1], $this->viewOf($this->acme, $this->grace));
        $this->assertSame(['list' => ['eu moved' => false, 'eu: CPU 91%' => false], 'unread' => 2], $this->viewOf($this->acme, $this->root));
        $this->assertSame(['list' => ['us moved' => false], 'unread' => 1], $this->viewOf($this->globex, $this->alan));
        $this->assertSame([$this->ada->id => 1], $this->statesIn($this->acme), 'the hidden resource threshold is not ada\'s to read');
        $this->assertSame([], $this->statesIn($this->globex));

        // The platform administrator reads the resource threshold in acme: for him only
        $cpu = $this->acme->run(fn () => Notification::query()->where('type', 'resource_threshold')->value('id'));
        $this->as($this->acme, $this->root)->patchJson("/api/notifications/{$cpu}/read")->assertOk()->assertJsonPath('id', $cpu);
        $this->as($this->acme, $this->ada)->patchJson("/api/notifications/{$cpu}/read")->assertNotFound();
        $this->assertSame(['list' => ['eu moved' => false, 'eu: CPU 91%' => true], 'unread' => 1], $this->viewOf($this->acme, $this->root));

        // Ada deletes everything she sees, grace one: each from their own list
        $this->as($this->acme, $this->ada)->deleteJson('/api/notifications')->assertOk();
        $this->as($this->acme, $this->grace)->deleteJson("/api/notifications/{$moved}")->assertOk();
        $this->assertSame(['list' => [], 'unread' => 0], $this->viewOf($this->acme, $this->ada));
        $this->assertSame(['list' => [], 'unread' => 0], $this->viewOf($this->acme, $this->grace));
        $this->assertSame(['list' => ['eu moved' => false, 'eu: CPU 91%' => true], 'unread' => 1], $this->viewOf($this->acme, $this->root));
        $this->assertSame(['list' => ['us moved' => false], 'unread' => 1], $this->viewOf($this->globex, $this->alan));

        // The notification rows themselves never change
        tenancy()->end();
        $this->assertSame(0, $this->acme->run(fn () => Notification::withTrashed()->whereNotNull('read_at')->orWhereNotNull('deleted_at')->count()));
        $this->assertSame(0, DB::connection('central')->table('notification_user_states')->count(), 'nothing in the central schema');
    }

    public function test_the_tenant_migration_adds_the_table_in_the_tenant_schema_only(): void
    {
        $migration = base_path(self::SCHEMA[1]);
        $id = $this->notify($this->acme, 'eu moved');
        $this->as($this->acme, $this->ada)->postJson('/api/notifications/read-all')->assertOk();
        tenancy()->end();

        $this->acme->run(fn () => (require $migration)->down());
        $this->assertFalse($this->acme->run(fn () => Schema::hasTable('notification_user_states')));
        $this->assertTrue($this->acme->run(fn () => Notification::query()->whereKey($id)->exists()), 'down drops the state table only');
        $this->assertTrue(Schema::connection('central')->hasTable('notification_user_states'), 'the central table stays');
        $this->assertTrue($this->globex->run(fn () => Schema::hasTable('notification_user_states')), 'another organization keeps its table');

        $this->acme->run(fn () => (require $migration)->up());
        $this->acme->run(fn () => (require $migration)->up());   // again: nothing to do
        $this->assertTrue($this->acme->run(fn () => Schema::hasColumns('notification_user_states', ['notification_id', 'user_id', 'read_at', 'deleted_at'])));
        $this->assertSame(['list' => ['eu moved' => false], 'unread' => 1], $this->viewOf($this->acme, $this->ada));
        $this->as($this->acme, $this->ada)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(['list' => ['eu moved' => true], 'unread' => 0], $this->viewOf($this->acme, $this->ada));
    }

    /**
     * notification-retention (PruneNotificationsJob): in the central schema and in every
     * organization's, the notifications past the retention go for good with their members' state,
     * and so do the notifications and state rows of users that no longer exist.
     */
    public function test_the_retention_prunes_the_central_schema_and_every_organizations(): void
    {
        config(['tenancy.enabled' => true]);
        Carbon::setTestNow('2026-11-04 12:00:00');
        $gone = 987654;   // an account deleted before 0.6.5
        $seed = function () use ($gone) {
            $old = Notification::create(['type' => 'region.migrated', 'level' => 'info', 'title' => 'old shared']);
            $old->forceFill(['created_at' => now()->subDays(91)])->save();
            Notification::create(['user_id' => (string) $this->ada->id, 'type' => 'deploy', 'level' => 'info', 'title' => 'old own'])
                ->forceFill(['created_at' => now()->subDays(120)])->save();
            $recent = Notification::create(['type' => 'region.migrated', 'level' => 'info', 'title' => 'recent shared']);
            Notification::create(['user_id' => (string) $gone, 'type' => 'deploy', 'level' => 'info', 'title' => 'of a deleted user']);
            foreach ([$old, $recent] as $notification) {
                foreach ([$this->ada->id, $this->grace->id, $gone] as $userId) {
                    NotificationUserState::query()->create(['notification_id' => $notification->id, 'user_id' => $userId, 'read_at' => now()]);
                }
            }

            return $recent->id;
        };
        $recent = ['central' => $seed(), 'acme' => $this->acme->run($seed), 'globex' => $this->globex->run($seed)];

        try {
            (new PruneNotificationsJob)->handle(app(NotificationRetention::class));
        } finally {
            Carbon::setTestNow();
        }

        $left = fn () => [
            Notification::withTrashed()->pluck('title')->all(),
            NotificationUserState::query()->orderBy('user_id')->get(['notification_id', 'user_id'])
                ->map(fn ($s) => [$s->notification_id, $s->user_id])->all(),
        ];
        foreach (['central' => null, 'acme' => $this->acme, 'globex' => $this->globex] as $where => $tenant) {
            $this->assertSame(
                [['recent shared'], [[$recent[$where], $this->ada->id], [$recent[$where], $this->grace->id]]],
                $tenant ? $tenant->run($left) : $left(),
                $where,
            );
        }
    }

    public function test_the_central_rows_of_a_deleted_organization_take_their_state_along(): void
    {
        // Central notifications of an organization (tenant_id) and of the platform, read by two members
        $central = DB::connection('central');
        $rows = ['acme' => $this->acme->id, 'platform' => null];
        foreach ($rows as $title => $tenantId) {
            $id = strtolower((string) Str::ulid());
            $central->table('notifications')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'type' => 'region.migrated', 'level' => 'info', 'title' => $title,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([$this->ada, $this->grace] as $user) {
                $central->table('notification_user_states')->insert([
                    'notification_id' => $id, 'user_id' => $user->id, 'read_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $rows[$title] = $id;
        }

        $deleted = TenantCentralTables::deleteTenantRows('central', $this->acme->id);

        $this->assertSame(1, $deleted['notifications'] ?? 0);
        $this->assertSame(0, $central->table('notification_user_states')->where('notification_id', $rows['acme'])->count());
        $this->assertSame(2, $central->table('notification_user_states')->where('notification_id', $rows['platform'])->count());
    }
}
