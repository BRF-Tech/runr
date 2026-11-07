<?php

namespace Tests\Feature\Rbac;

use App\Models\PlatformAdvisory;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\HorizonAccess;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * platform.manage (single-tenant edition, docs/permissions.md#platform-administrators): a
 * single-tenant install has no platform administrators (users.is_admin comes with the Tenancy
 * module), so the holders of platform.manage operate the installation itself — the Patches page,
 * GET admin/console-tls, the platform's banners, the Horizon dashboard. The Owner role holds it;
 * a custom role can. Multi-tenant installs are unchanged: TenantPlatformManageTest.
 */
class PlatformManagePermissionTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    /** What the console reaches as the installation's operator */
    private const ROUTES = [
        ['GET', '/api/admin/runtime-patches'],
        ['GET', '/api/admin/console-tls'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // the feed off (an empty key): its banner is a platform banner every operator sees
        config(['runtime_patches.public_key' => '', 'app.version' => 'v0.6.7']);
        PlatformAdvisory::query()->create(['version' => '0.6.8', 'level' => 'security', 'reason' => ['en' => 'Fixes the API'], 'in_feed' => true]);
        // no cluster: the console certificate is not read
        Http::fake();
    }

    /** @return list<string> the platform banners $user sees */
    private function platformBanners(User $user): array
    {
        return collect($this->signedIn($user)->getJson('/api/runtime-patches/banners')->assertOk()->json('data'))
            ->pluck('kind')->reject(fn (string $kind) => $kind === 'runtime_patch')->values()->all();
    }

    private function assertOperates(User $user, string $who): void
    {
        foreach (self::ROUTES as [$method, $uri]) {
            $this->signedIn($user)->json($method, $uri)->assertOk();
        }
        $this->assertSame(['platform_advisory', 'feed_disabled'], $this->platformBanners($user), "{$who}: the platform banners");
        $this->assertTrue(app(HorizonAccess::class)->isAdministrator($user), "{$who}: Horizon");
        $this->signedIn($user)->postJson('/api/horizon/link')->assertOk();
    }

    private function assertDoesNotOperate(User $user, string $who): void
    {
        foreach (self::ROUTES as [$method, $uri]) {
            $this->signedIn($user)->json($method, $uri)->assertForbidden()
                ->assertJsonPath('permissions', [PermissionCatalog::PLATFORM_MANAGE]);
        }
        $this->assertSame([], $this->platformBanners($user), "{$who}: no platform banners");
        $this->assertFalse(app(HorizonAccess::class)->isAdministrator($user), "{$who}: no Horizon");
        $this->signedIn($user)->postJson('/api/horizon/link')->assertForbidden();
    }

    public function test_a_single_tenant_owner_operates_the_installation(): void
    {
        $owner = $this->userWithRole('owner');

        $this->assertTrue(app(Rbac::class)->operatesPlatform($owner));
        $this->assertOperates($owner, 'owner');
        $this->assertContains(PermissionCatalog::PLATFORM_MANAGE, $this->signedIn($owner)->getJson('/api/auth/permissions')->json('permissions'));
        $this->assertFalse($this->signedIn($owner)->getJson('/api/auth/permissions')->json('platform_admin'), 'still no platform administrator');
    }

    public function test_the_other_built_in_roles_and_the_upgrades_member_role_do_not(): void
    {
        $this->userWithRole('owner');
        $this->customRole('member', config('permissions.legacy_member_role.permissions'), 'Member');

        foreach (['admin', 'developer', 'viewer', 'billing', 'member'] as $role) {
            $user = $this->userWithRole($role);
            $this->assertFalse(app(Rbac::class)->operatesPlatform($user), $role);
            $this->assertDoesNotOperate($user, $role);
            $this->assertNotContains(PermissionCatalog::PLATFORM_MANAGE, $this->signedIn($user)->getJson('/api/auth/permissions')->json('permissions'), $role);
        }
    }

    public function test_the_role_editor_offers_it_and_a_custom_role_with_it_operates_the_installation(): void
    {
        $owner = $this->userWithRole('owner');
        $admin = $this->userWithRole('admin');
        $dana = $this->userWithRole('developer');

        $catalog = collect($this->signedIn($owner)->getJson('/api/permissions')->assertOk()->json('permissions'))->keyBy('key');
        $this->assertSame(['key' => 'platform.manage', 'group' => 'infrastructure', 'scope' => 'org', 'implies' => []], $catalog['platform.manage']);

        // nobody hands out what they do not hold: an Admin cannot create such a role, the Owner can
        $this->signedIn($admin)->postJson('/api/roles', ['name' => 'Operator', 'key' => 'operator', 'permissions' => ['platform.manage', 'regions.view']])
            ->assertForbidden();
        $this->signedIn($owner)->postJson('/api/roles', ['name' => 'Operator', 'key' => 'operator', 'permissions' => ['platform.manage', 'regions.view']])
            ->assertCreated()
            ->assertJsonPath('role.permissions', ['regions.view', 'platform.manage']);

        $this->signedIn($admin)->putJson("/api/members/{$dana->id}/role", ['role' => 'operator'])->assertForbidden();
        $this->signedIn($owner)->putJson("/api/members/{$dana->id}/role", ['role' => 'operator'])->assertOk();
        $this->flushRbac();
        $this->assertOperates($dana, 'custom role');

        // through a group: the Admin keeps the Admin role and gains the installation
        $this->assertDoesNotOperate($admin, 'admin');
        $this->groupWithRole('operators', 'operator', [$admin]);
        $this->assertOperates($admin, 'admin in the operators group');
    }

    public function test_a_scoped_api_token_reaches_the_installation_only_with_platform_manage(): void
    {
        $owner = $this->userWithRole('owner');
        $limited = $owner->createToken('ci', ['projects.view', 'regions.manage'])->plainTextToken;
        $operator = $owner->createToken('ops', ['platform.manage'])->plainTextToken;
        $everything = $owner->createToken('all', ['*'])->plainTextToken;

        $as = function (string $token) {
            $this->app['auth']->forgetGuards();

            return $this->withHeader('Authorization', 'Bearer '.$token);
        };

        foreach (self::ROUTES as [$method, $uri]) {
            $as($limited)->json($method, $uri)->assertForbidden()->assertJsonPath('permissions', ['platform.manage']);
            $as($operator)->json($method, $uri)->assertOk();
            $as($everything)->json($method, $uri)->assertOk();
        }
        $this->assertSame([], collect($as($limited)->getJson('/api/runtime-patches/banners')->assertOk()->json('data'))->pluck('kind')->all());
        $this->assertSame(['platform_advisory', 'feed_disabled'], collect($as($operator)->getJson('/api/runtime-patches/banners')->json('data'))->pluck('kind')->all());

        // Horizon retries and deletes jobs: an unscoped token only, as before
        $as($operator)->postJson('/api/horizon/link')->assertForbidden();
        $as($everything)->postJson('/api/horizon/link')->assertOk();
    }

    /**
     * An install upgraded to this release keeps its role data as it is: built-in roles are not
     * stored but read from config/permissions.php, so the stored Owner assignment holds
     * platform.manage from the first request on — no migration, no seeder. The rows below are what
     * a 0.6.7 install has (and what the 0.5.0 upgrade wrote for 0.4.x users: the administrator
     * account Owner, everyone else Admin); stored custom roles keep their list.
     */
    public function test_an_existing_single_tenant_install_gets_it_on_its_owner_without_touching_stored_roles(): void
    {
        $users = [];
        foreach (['olga' => 'owner', 'adam' => 'admin', 'dev' => 'developer', 'ops' => 'ops', 'grouped' => 'viewer', 'late' => null] as $username => $role) {
            $users[$username] = User::factory()->create(['username' => $username]);
        }
        // the role data as stored by 0.6.7: the hook that gave the users a role above is not 0.6.7's
        DB::table('rbac_role_assignments')->delete();
        $now = now();
        $assign = fn (string $type, string $subject, string $role) => DB::table('rbac_role_assignments')->insert([
            'id' => (string) Str::ulid(), 'subject_type' => $type, 'subject_id' => $subject, 'role' => $role, 'project_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (['olga' => 'owner', 'adam' => 'admin', 'dev' => 'developer', 'ops' => 'ops', 'grouped' => 'viewer'] as $username => $role) {
            $assign(RoleAssignment::SUBJECT_USER, (string) $users[$username]->id, $role);
        }
        // a custom role saved by 0.6.7's role editor (expanded list, no platform.manage then)
        DB::table('rbac_roles')->insert([
            'id' => (string) Str::ulid(), 'key' => 'ops', 'name' => 'Ops', 'description' => null,
            'permissions' => json_encode(['regions.view', 'regions.manage', 'runtimes.manage', 'settings.manage']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $group = (string) Str::ulid();
        DB::table('rbac_groups')->insert(['id' => $group, 'name' => 'Platform team', 'created_at' => $now, 'updated_at' => $now]);
        $assign(RoleAssignment::SUBJECT_GROUP, $group, 'admin');
        DB::table('rbac_group_user')->insert(['group_id' => $group, 'user_id' => $users['grouped']->id, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);

        $stored = fn () => [
            DB::table('rbac_role_assignments')->orderBy('id')->get(['subject_type', 'subject_id', 'role', 'project_id'])->map(fn ($r) => (array) $r)->all(),
            DB::table('rbac_roles')->orderBy('id')->get(['key', 'permissions'])->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $stored();

        // what the Helm bootstrap Job runs after an upgrade: a role for the account without one
        $this->assertSame(0, Artisan::call('rbac:assign-missing'));
        $this->flushRbac();
        $this->assertSame('developer', (new SingleTenantOrganization)->memberRole($users['late']->id));

        $this->assertOperates($users['olga'], 'the stored Owner');
        foreach (['adam', 'dev', 'ops', 'grouped', 'late'] as $username) {
            $this->assertDoesNotOperate($users[$username], $username);
        }

        // nothing stored changed (the late account got its role, as before this release)
        [$assignments, $roles] = $stored();
        $this->assertSame($before[1], $roles, 'custom roles keep their stored permissions');
        $this->assertSame($before[0], array_values(array_filter($assignments, fn (array $a) => $a['subject_id'] !== (string) $users['late']->id)));

        // the Owner adds it to the existing custom role in the role editor: its members get it
        $this->signedIn($users['olga'])->putJson('/api/roles/ops', ['permissions' => ['regions.manage', 'runtimes.manage', 'settings.manage', 'platform.manage']])
            ->assertOk();
        $this->flushRbac();
        $this->assertOperates($users['ops'], 'the Ops role with platform.manage');
    }

    public function test_the_platform_administrator_flag_still_counts_where_it_exists(): void
    {
        $this->userWithRole('owner');
        $root = $this->platformAdmin();

        $this->assertTrue(app(Rbac::class)->operatesPlatform($root));
        $this->assertOperates($root, 'platform administrator');
    }
}
