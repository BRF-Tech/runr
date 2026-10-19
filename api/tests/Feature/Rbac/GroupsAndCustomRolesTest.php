<?php

namespace Tests\Feature\Rbac;

use App\Models\Group;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Groups (roles for every member) and custom roles (permission matrix) — API and guards.
 */
class GroupsAndCustomRolesTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
    }

    // ── Groups ──────────────────────────────────────────────────────────────

    public function test_a_groups_role_adds_to_the_members_own_role(): void
    {
        $user = $this->userWithRole('viewer');
        $this->assertFalse($this->can($user, 'projects.deploy'));

        $this->groupWithRole('engineering', 'developer', [$user]);

        $this->assertTrue($this->can($user, 'projects.deploy'));
        $this->assertTrue($this->can($user, 'logs.view'));
        $this->assertFalse($this->can($user, 'regions.manage'));
    }

    public function test_groups_are_managed_through_the_api(): void
    {
        $alice = $this->userWithRole('viewer', ['name' => 'Alice']);
        $bob = $this->userWithRole('viewer', ['name' => 'Bob']);

        $id = $this->signedIn($this->owner)->postJson('/api/groups', ['name' => 'SRE', 'role' => 'developer'])
            ->assertCreated()->assertJsonPath('group.role', 'developer')->json('group.id');

        $this->signedIn($this->owner)->postJson("/api/groups/{$id}/members", ['user_ids' => [$alice->id, $bob->id]])
            ->assertOk()->assertJsonCount(2, 'group.members');
        $this->assertTrue($this->can($alice, 'shell.exec'));

        $this->signedIn($this->owner)->deleteJson("/api/groups/{$id}/members/{$bob->id}")
            ->assertOk()->assertJsonCount(1, 'group.members');
        $this->assertFalse($this->can($bob, 'shell.exec'));

        $list = $this->signedIn($this->owner)->getJson('/api/groups')->assertOk();
        $list->assertJsonPath('groups.0.name', 'SRE')->assertJsonPath('groups.0.member_count', 1);

        $this->signedIn($this->owner)->putJson("/api/groups/{$id}", ['role' => null])->assertOk()->assertJsonPath('group.role', null);
        $this->assertFalse($this->can($alice, 'shell.exec'));

        $this->signedIn($this->owner)->deleteJson("/api/groups/{$id}")->assertOk();
        $this->assertSame(0, DB::table('rbac_group_user')->count());
        $this->assertSame(0, RoleAssignment::where('subject_type', 'group')->count());
    }

    public function test_group_names_are_unique(): void
    {
        $this->groupWithRole('ops', null);

        $this->signedIn($this->owner)->postJson('/api/groups', ['name' => 'ops'])->assertUnprocessable();
    }

    public function test_the_owner_role_is_not_for_groups(): void
    {
        $this->signedIn($this->owner)->postJson('/api/groups', ['name' => 'owners', 'role' => 'owner'])->assertUnprocessable();
        $this->assertSame(0, Group::count());
    }

    public function test_group_managers_cannot_hand_out_roles_they_do_not_hold(): void
    {
        $this->customRole('team-lead', ['groups.manage', 'members.view']);
        $lead = $this->userWithRole('team-lead');
        $admins = $this->groupWithRole('admins', 'admin');
        $user = $this->userWithRole('viewer');

        $this->signedIn($lead)->postJson("/api/groups/{$admins->id}/members", ['user_ids' => [$user->id]])->assertForbidden();
        $this->assertFalse($this->can($user, 'regions.manage'));

        // Setting a group role also needs roles.manage
        $plain = $this->groupWithRole('plain', null);
        $this->signedIn($lead)->putJson("/api/groups/{$plain->id}", ['role' => 'viewer'])->assertForbidden();
        $this->assertAllowed($this->signedIn($lead)->postJson("/api/groups/{$plain->id}/members", ['user_ids' => [$user->id]]));
    }

    public function test_members_see_groups_but_only_group_managers_change_them(): void
    {
        $viewer = $this->userWithRole('viewer');
        $group = $this->groupWithRole('ops', null);

        $this->signedIn($viewer)->getJson('/api/groups')->assertOk();
        $this->signedIn($viewer)->getJson("/api/groups/{$group->id}")->assertOk();
        $this->signedIn($viewer)->postJson('/api/groups', ['name' => 'x'])->assertForbidden();
        $this->signedIn($viewer)->deleteJson("/api/groups/{$group->id}")->assertForbidden();
    }

    public function test_only_organization_members_join_groups(): void
    {
        $group = $this->groupWithRole('ops', null);

        $this->signedIn($this->owner)->postJson("/api/groups/{$group->id}/members", ['user_ids' => [987654]])->assertUnprocessable();
    }

    // ── Custom roles ────────────────────────────────────────────────────────

    public function test_a_custom_role_is_created_from_the_permission_matrix(): void
    {
        $response = $this->signedIn($this->owner)->postJson('/api/roles', [
            'name' => 'Release manager',
            'description' => 'Deploys, does not code',
            'permissions' => ['projects.deploy', 'logs.view'],
        ])->assertCreated();

        $response->assertJsonPath('role.key', 'release-manager')->assertJsonPath('role.builtin', false);
        // Implied permissions are part of the role
        $this->assertSame(['projects.view', 'projects.deploy', 'logs.view'], $response->json('role.permissions'));

        $user = $this->userWithRole('release-manager');
        $this->assertTrue($this->can($user, 'projects.deploy'));
        $this->assertFalse($this->can($user, 'code.edit'));
    }

    public function test_a_custom_role_can_be_assigned_and_edited(): void
    {
        $this->customRole('auditor', ['audit.view']);
        $user = $this->userWithRole('viewer');

        $this->signedIn($this->owner)->putJson("/api/members/{$user->id}/role", ['role' => 'auditor'])->assertOk();
        $this->assertTrue($this->can($user, 'audit.view'));
        $this->assertFalse($this->can($user, 'audit.export'));

        $this->signedIn($this->owner)->putJson('/api/roles/auditor', ['permissions' => ['audit.export']])->assertOk();
        $this->assertTrue($this->can($user, 'audit.export'));
    }

    public function test_custom_roles_never_exceed_their_editor(): void
    {
        $this->customRole('role-admin', ['roles.manage', 'projects.view']);
        $editor = $this->userWithRole('role-admin');

        $this->signedIn($editor)->postJson('/api/roles', ['name' => 'Infra', 'permissions' => ['regions.manage']])->assertForbidden();
        $this->signedIn($editor)->postJson('/api/roles', ['name' => 'Reader', 'permissions' => ['projects.view']])->assertCreated();
        $this->assertNull(Role::where('key', 'infra')->first());
    }

    public function test_built_in_roles_are_read_only_and_their_keys_reserved(): void
    {
        $this->signedIn($this->owner)->putJson('/api/roles/developer', ['permissions' => ['regions.manage']])->assertUnprocessable();
        $this->signedIn($this->owner)->deleteJson('/api/roles/viewer')->assertUnprocessable();
        $this->signedIn($this->owner)->postJson('/api/roles', ['name' => 'Admin', 'permissions' => []])->assertUnprocessable();
        $this->signedIn($this->owner)->postJson('/api/roles', ['name' => 'Billing', 'permissions' => []])->assertUnprocessable();
    }

    public function test_unknown_permissions_are_refused(): void
    {
        $this->signedIn($this->owner)->postJson('/api/roles', ['name' => 'X', 'permissions' => ['projects.fly']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
    }

    public function test_deleting_a_custom_role_moves_its_members_to_the_default_role(): void
    {
        $this->customRole('contractor', ['projects.view']);
        $user = $this->userWithRole('contractor');
        $group = $this->groupWithRole('vendors', 'contractor');
        $project = $this->caasProject();
        $this->grantProject($group, $project, 'contractor');

        $usage = collect($this->signedIn($this->owner)->getJson('/api/roles')->assertOk()->json('roles'))->firstWhere('key', 'contractor')['usage'];
        $this->assertSame(['members' => 1, 'groups' => 1, 'projects' => 1], $usage);

        $this->signedIn($this->owner)->deleteJson('/api/roles/contractor')->assertOk();

        $this->assertSame('developer', (new SingleTenantOrganization)->memberRole($user->id));
        $this->assertNull($group->orgRole());
        $this->assertSame(0, RoleAssignment::where('role', 'contractor')->count());
    }

    public function test_the_role_list_tells_which_roles_the_caller_can_grant(): void
    {
        $admin = $this->userWithRole('admin');
        $roles = collect($this->signedIn($admin)->getJson('/api/roles')->assertOk()->json('roles'))->keyBy('key');

        $this->assertFalse($roles['owner']['grantable'], 'only owners grant Owner');
        $this->assertTrue($roles['developer']['grantable']);
        $this->assertTrue($roles['owner']['builtin']);
        $this->assertSame('developer', $this->signedIn($admin)->getJson('/api/roles')->json('default_role'));
    }

    public function test_the_catalog_endpoint_lists_the_edition(): void
    {
        $data = $this->signedIn($this->userWithRole('viewer'))->getJson('/api/permissions')->assertOk()->json();

        $this->assertSame('single', $data['edition']);
        $keys = collect($data['permissions'])->pluck('key');
        $this->assertContains('runtimes.use_custom', $keys);
        // Billing works without tenancy (docs/billing.md); the tenant-only permissions do not
        $this->assertContains('billing.view', $keys);
        $this->assertNotContains('tenants.residency.manage', $keys);
        $this->assertSame('project', collect($data['permissions'])->firstWhere('key', 'shell.exec')['scope']);
    }
}
