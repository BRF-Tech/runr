<?php

namespace Tests\Feature\Rbac;

use App\Models\KnativeRegion;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Single-tenant install: the install is the organization, members' roles are role assignments.
 * Built-in role matrix, enforcement through the API, ownership rules, default role.
 */
class SingleTenantRolesTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private function role(User $user): ?string
    {
        return (new SingleTenantOrganization)->memberRole($user->id);
    }

    // ── Membership ──────────────────────────────────────────────────────────

    public function test_the_first_user_is_owner_and_later_users_get_the_default_role(): void
    {
        $first = User::factory()->create(['username' => 'first']);
        $second = User::factory()->create(['username' => 'second']);

        $this->assertSame('owner', $this->role($first));
        $this->assertSame('developer', $this->role($second));
    }

    public function test_the_default_role_setting_applies_to_new_users(): void
    {
        $owner = $this->userWithRole('owner');
        $this->signedIn($owner)->putJson('/api/roles/default', ['role' => 'viewer'])
            ->assertOk()->assertJsonPath('default_role', 'viewer');

        $this->assertSame('viewer', Setting::get(AccessManager::SETTING_DEFAULT_ROLE));
        $this->assertSame('viewer', $this->role(User::factory()->create(['username' => 'later'])));
    }

    public function test_the_default_role_is_never_owner(): void
    {
        $owner = $this->userWithRole('owner');

        $this->signedIn($owner)->putJson('/api/roles/default', ['role' => 'owner'])->assertUnprocessable();
        $this->signedIn($owner)->putJson('/api/roles/default', ['role' => 'nope'])->assertUnprocessable();
    }

    public function test_users_created_with_a_role(): void
    {
        $owner = $this->userWithRole('owner');

        $this->signedIn($owner)->postJson('/api/users', [
            'name' => 'Vera', 'username' => 'vera', 'email' => 'vera@example.test', 'password' => 'secret-pass', 'role' => 'viewer',
        ])->assertCreated()->assertJsonPath('user.role', 'viewer');

        $this->assertSame('viewer', $this->role(User::where('username', 'vera')->first()));
    }

    public function test_the_user_list_shows_roles(): void
    {
        $owner = $this->userWithRole('owner');
        $this->userWithRole('viewer', ['username' => 'vic']);

        $users = collect($this->signedIn($owner)->getJson('/api/users')->assertOk()->json('users'))->keyBy('username');
        $this->assertSame('viewer', $users['vic']['role']);
        $this->assertSame('owner', $users[$owner->username]['role']);
    }

    // ── Built-in role matrix ────────────────────────────────────────────────

    public static function matrix(): array
    {
        return [
            'owner' => ['owner', ['projects.deploy', 'regions.manage', 'roles.manage', 'members.remove', 'settings.manage', 'tickets.manage', 'secrets.view_values'], []],
            'admin' => ['admin', ['projects.deploy', 'regions.manage', 'roles.manage', 'sso.manage', 'audit.export', 'runtimes.manage'], []],
            'developer' => ['developer', ['projects.create', 'projects.deploy', 'code.edit', 'env.edit', 'shell.exec', 'domains.manage', 'members.view', 'tokens.manage'], ['regions.manage', 'members.invite', 'roles.manage', 'settings.manage', 'sso.manage', 'secrets.view_values', 'audit.view']],
            'viewer' => ['viewer', ['projects.view', 'logs.view', 'metrics.view', 'regions.view'], ['projects.create', 'projects.update', 'projects.deploy', 'code.edit', 'env.view', 'shell.exec', 'regions.manage']],
        ];
    }

    #[DataProvider('matrix')]
    public function test_built_in_roles(string $role, array $allowed, array $denied): void
    {
        $this->userWithRole('owner');
        $user = $role === 'owner' ? User::query()->first() : $this->userWithRole($role);

        foreach ($allowed as $permission) {
            $this->assertTrue($this->can($user, $permission), "{$role} may {$permission}");
        }
        foreach ($denied as $permission) {
            $this->assertFalse($this->can($user, $permission), "{$role} may not {$permission}");
        }
    }

    public function test_the_billing_role_in_a_single_tenant_install(): void
    {
        // Billing works without tenancy (docs/billing.md): bills, prices, approvals, stopping projects
        $this->userWithRole('owner');
        $user = $this->userWithRole('billing');

        foreach (['billing.view', 'billing.manage', 'billing.approve', 'projects.stop'] as $permission) {
            $this->assertTrue($this->can($user, $permission), "billing may {$permission}");
        }
        $this->assertFalse($this->can($user, 'projects.view'));
    }

    public function test_a_platform_admin_holds_every_permission(): void
    {
        $this->userWithRole('owner');
        $admin = $this->platformAdmin();

        foreach (['regions.manage', 'roles.manage', 'tickets.manage', 'billing.manage'] as $permission) {
            $this->assertTrue($this->can($admin, $permission));
            $this->assertTrue($admin->can($permission), 'Gate::before');
        }
    }

    // ── Enforcement through the API ─────────────────────────────────────────

    public function test_developer_works_on_projects_but_does_not_run_the_organization(): void
    {
        $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $project = $this->caasProject();

        $this->signedIn($dev)->getJson('/api/projects')->assertOk();
        $this->signedIn($dev)->putJson("/api/projects/{$project->id}", ['description' => 'checkout'])->assertOk();
        $this->assertAllowed($this->signedIn($dev)->postJson("/api/projects/{$project->id}/deploy", []));
        $this->assertAllowed($this->signedIn($dev)->postJson("/api/projects/{$project->id}/domains", []));
        $this->signedIn($dev)->getJson('/api/users')->assertOk();

        $this->signedIn($dev)->postJson('/api/knative-regions', [])->assertForbidden();
        $this->signedIn($dev)->patchJson('/api/settings', ['registration_enabled' => false])->assertForbidden();
        $this->signedIn($dev)->postJson('/api/users', [])->assertForbidden();
        $this->signedIn($dev)->postJson('/api/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();
    }

    public function test_viewer_is_read_only(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $project = $this->caasProject();

        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}")->assertOk();
        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/deployments")->assertOk();
        $this->signedIn($viewer)->getJson('/api/knative-regions')->assertOk();

        $this->signedIn($viewer)->putJson("/api/projects/{$project->id}", ['description' => 'x'])->assertForbidden();
        $this->signedIn($viewer)->postJson("/api/projects/{$project->id}/deploy", [])->assertForbidden();
        $this->signedIn($viewer)->postJson("/api/projects/{$project->id}/files", [])->assertForbidden();
        $this->signedIn($viewer)->deleteJson("/api/projects/{$project->id}")->assertForbidden();
        $this->signedIn($viewer)->postJson('/api/projects', [])->assertForbidden();
    }

    public function test_admin_manages_regions_settings_and_members(): void
    {
        $this->userWithRole('owner');
        $admin = $this->userWithRole('admin');

        $this->assertAllowed($this->signedIn($admin)->postJson('/api/knative-regions', []));
        $this->signedIn($admin)->patchJson('/api/settings', ['gpu_demo_enabled' => true])->assertOk();
        $this->assertAllowed($this->signedIn($admin)->postJson('/api/users', []));
    }

    public function test_viewer_does_not_see_environment_variables(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $dev = $this->userWithRole('developer');
        $project = $this->caasProject('shop', ['API_KEY' => 's3cr3t']);

        $hidden = $this->signedIn($viewer)->getJson("/api/projects/{$project->id}")->assertOk();
        $hidden->assertJsonPath('data.config.env_vars', null)->assertJsonPath('data.config.env_vars_hidden', true);
        $this->assertStringNotContainsString('s3cr3t', $hidden->getContent());

        $this->signedIn($dev)->getJson("/api/projects/{$project->id}")
            ->assertOk()->assertJsonPath('data.config.env_vars.API_KEY', 's3cr3t');
    }

    // ── Ownership ───────────────────────────────────────────────────────────

    public function test_only_owners_grant_the_owner_role(): void
    {
        $owner = $this->userWithRole('owner');
        $admin = $this->userWithRole('admin');
        $dev = $this->userWithRole('developer');

        $this->signedIn($admin)->putJson("/api/members/{$dev->id}/role", ['role' => 'owner'])->assertForbidden();
        $this->signedIn($owner)->putJson("/api/members/{$dev->id}/role", ['role' => 'owner'])->assertOk();
        $this->assertSame('owner', $this->role($dev));

        // … and only owners take it away
        $this->signedIn($admin)->putJson("/api/members/{$dev->id}/role", ['role' => 'viewer'])->assertForbidden();
    }

    public function test_the_last_owner_keeps_the_role(): void
    {
        $owner = $this->userWithRole('owner');
        $root = $this->platformAdmin();

        $this->signedIn($root)->putJson("/api/members/{$owner->id}/role", ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->signedIn($root)->deleteJson("/api/users/{$owner->id}")->assertUnprocessable();
        $this->assertSame('owner', $this->role($owner));
    }

    public function test_nobody_changes_their_own_role(): void
    {
        $owner = $this->userWithRole('owner');

        $this->signedIn($owner)->putJson("/api/members/{$owner->id}/role", ['role' => 'viewer'])->assertUnprocessable();
    }

    public function test_roles_with_more_permissions_than_the_granter_are_refused(): void
    {
        $this->userWithRole('owner');
        $this->customRole('people-ops', ['roles.manage', 'members.view']);
        $ops = $this->userWithRole('people-ops');
        $dev = $this->userWithRole('viewer');

        $this->signedIn($ops)->putJson("/api/members/{$dev->id}/role", ['role' => 'developer'])->assertForbidden();
        $this->assertSame('viewer', $this->role($dev));
    }

    public function test_members_with_more_permissions_cannot_be_edited_or_removed(): void
    {
        $owner = $this->userWithRole('owner');
        $this->customRole('helpdesk', ['members.view', 'members.update', 'members.remove']);
        $helpdesk = $this->userWithRole('helpdesk');

        $this->signedIn($helpdesk)->patchJson("/api/users/{$owner->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->signedIn($helpdesk)->deleteJson("/api/users/{$owner->id}")->assertForbidden();
        $this->assertNotSame('Hijacked', $owner->fresh()->name);
    }

    public function test_deleting_a_user_drops_their_roles_and_groups(): void
    {
        $owner = $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $this->groupWithRole('ops', 'admin', [$dev]);
        $this->grantProject($dev, $this->caasProject(), 'admin');

        $this->signedIn($owner)->deleteJson("/api/users/{$dev->id}")->assertOk();

        $this->assertSame(0, RoleAssignment::where('subject_type', 'user')->where('subject_id', (string) $dev->id)->count());
        $this->assertSame(0, DB::table('rbac_group_user')->where('user_id', $dev->id)->count());
    }

    // ── Effective permissions endpoint ──────────────────────────────────────

    public function test_the_console_reads_its_permissions(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $project = $this->caasProject();
        $this->grantProject($viewer, $project, 'developer');

        $data = $this->signedIn($viewer)->getJson('/api/auth/permissions')->assertOk()->json();

        $this->assertSame('single', $data['edition']);
        $this->assertSame('viewer', $data['role']);
        $this->assertFalse($data['platform_admin']);
        $this->assertContains('projects.view', $data['permissions']);
        $this->assertNotContains('projects.deploy', $data['permissions']);
        $this->assertContains('projects.deploy', $data['projects'][$project->id]);
        $this->assertNotContains('projects.view', $data['projects'][$project->id], 'only what the organization role lacks');
    }

    // ── Response cache ──────────────────────────────────────────────────────

    public function test_cached_project_lists_are_per_user(): void
    {
        $owner = $this->userWithRole('owner');
        $this->customRole('guest', []);
        $guest = $this->userWithRole('guest');
        $this->caasProject('alpha');
        $beta = $this->caasProject('beta');
        $this->grantProject($guest, $beta, 'viewer');

        $this->assertCount(2, $this->signedIn($owner)->getJson('/api/projects')->assertOk()->json('data'));
        $this->assertSame(['beta'], collect($this->signedIn($guest)->getJson('/api/projects')->assertOk()->json('data'))->pluck('name')->all());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_regions_are_listed_for_viewers(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        KnativeRegion::create(['name' => 'eu', 'is_active' => true, 'is_demo' => true]);

        $this->assertCount(1, $this->signedIn($viewer)->getJson('/api/knative-regions')->assertOk()->json('data'));
    }
}
