<?php

namespace Tests\Feature\Rbac;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Upgrade from 0.4.x without lockout: single-tenant users keep full access (Admin, the admin
 * account Owner), 0.4.x token scopes become permissions.
 */
class UpgradeMigrationTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private function runMigration(string $name): void
    {
        (require base_path("database/migrations/{$name}.php"))->up();
        $this->flushRbac();
    }

    /** Users as they were before roles existed: no role assignments. */
    private function legacyUsers(string ...$usernames): array
    {
        $users = array_map(fn (string $u) => User::factory()->create(['username' => $u]), $usernames);
        RoleAssignment::query()->delete();

        return $users;
    }

    private function role(User $user): ?string
    {
        return (new SingleTenantOrganization)->memberRole($user->id);
    }

    public function test_single_tenant_users_keep_everything_they_could_do(): void
    {
        config(['app.admin.username' => 'admin']);
        [$early, $admin, $late] = $this->legacyUsers('early', 'admin', 'late');

        $this->runMigration('2026_10_10_000002_assign_roles_to_existing_users');

        $this->assertSame('owner', $this->role($admin), 'the administrator account owns the install');
        $this->assertSame('admin', $this->role($early));
        $this->assertSame('admin', $this->role($late));
        foreach (['regions.manage', 'members.invite', 'settings.manage', 'sso.manage', 'projects.deploy'] as $permission) {
            $this->assertTrue($this->can($late, $permission), "kept {$permission}");
        }
    }

    public function test_without_the_admin_account_the_oldest_user_is_owner(): void
    {
        config(['app.admin.username' => 'nobody-here']);
        [$first, $second] = $this->legacyUsers('first', 'second');
        DB::table('users')->where('id', $first->id)->update(['created_at' => now()->subYear()]);

        $this->runMigration('2026_10_10_000002_assign_roles_to_existing_users');

        $this->assertSame('owner', $this->role($first));
        $this->assertSame('admin', $this->role($second));
    }

    public function test_existing_assignments_are_kept(): void
    {
        [$a, $b] = $this->legacyUsers('a', 'b');
        (new SingleTenantOrganization)->setMemberRole($b->id, 'viewer');

        $this->runMigration('2026_10_10_000002_assign_roles_to_existing_users');
        $this->runMigration('2026_10_10_000002_assign_roles_to_existing_users');

        $this->assertSame('viewer', $this->role($b));
        $this->assertSame(2, RoleAssignment::query()->whereNull('project_id')->count());
    }

    public function test_multi_tenant_installs_keep_the_role_on_the_membership(): void
    {
        $this->legacyUsers('a', 'b');
        config(['tenancy.enabled' => true]);

        $this->runMigration('2026_10_10_000002_assign_roles_to_existing_users');

        $this->assertSame(0, RoleAssignment::query()->count());
    }

    public function test_token_scopes_of_0_4_become_permissions(): void
    {
        [$user] = $this->legacyUsers('ci');
        $legacy = $user->createToken('legacy', ['projects:read', 'deployments:write'])->accessToken;
        $full = $user->createToken('full', ['*'])->accessToken;
        $current = $user->createToken('current', ['logs.view'])->accessToken;

        $this->runMigration('2026_10_10_000003_translate_legacy_token_abilities');

        $this->assertSame(
            ['projects.view', 'logs.view', 'metrics.view', 'git.pull', 'projects.deploy'],
            $legacy->fresh()->abilities,
        );
        $this->assertSame(['*'], $full->fresh()->abilities);
        $this->assertSame(['logs.view'], $current->fresh()->abilities);
    }
}
