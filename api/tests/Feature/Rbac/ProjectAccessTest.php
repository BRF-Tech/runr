<?php

namespace Tests\Feature\Rbac;

use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Project access: a role on one project only (user or group), project-scoped permissions only,
 * project lists filtered, creators keep access, granting guarded, environment variables.
 */
class ProjectAccessTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
    }

    public function test_viewer_everywhere_and_developer_on_one_project(): void
    {
        $checkout = $this->caasProject('checkout');
        $billing = $this->caasProject('billing');
        $user = $this->userWithRole('viewer');
        $this->grantProject($user, $checkout, 'developer');

        $this->assertTrue($this->can($user, 'projects.deploy', $checkout));
        $this->assertTrue($this->can($user, 'code.edit', $checkout->id));
        $this->assertFalse($this->can($user, 'projects.deploy', $billing));
        $this->assertTrue($this->can($user, 'projects.view', $billing), 'viewer organization-wide');

        $this->assertAllowed($this->signedIn($user)->postJson("/api/projects/{$checkout->id}/deploy", []));
        $this->signedIn($user)->postJson("/api/projects/{$billing->id}/deploy", [])->assertForbidden();
    }

    public function test_only_project_scoped_permissions_apply_on_a_project(): void
    {
        $project = $this->caasProject();
        $this->customRole('guest', []);
        $user = $this->userWithRole('guest');
        $this->grantProject($user, $project, 'admin');

        $this->assertTrue($this->can($user, 'shell.exec', $project));
        $this->assertTrue($this->can($user, 'projects.manage_access', $project));
        $this->assertFalse($this->can($user, 'regions.manage', $project));
        $this->assertFalse($this->can($user, 'members.invite'));
        $this->assertFalse($this->can($user, 'projects.create'));
    }

    public function test_project_scoped_members_only_list_and_open_their_projects(): void
    {
        $mine = $this->caasProject('mine');
        $other = $this->caasProject('other');
        $this->customRole('guest', []);
        $user = $this->userWithRole('guest');
        $this->grantProject($user, $mine, 'viewer');

        $names = collect($this->signedIn($user)->getJson('/api/projects')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['mine'], $names);

        $this->signedIn($user)->getJson("/api/projects/{$mine->id}")->assertOk();
        $this->signedIn($user)->getJson("/api/projects/{$other->id}")->assertForbidden();
        // Code endpoints take the id as a string, not a bound model
        $this->signedIn($user)->getJson("/api/projects/{$other->id}/versions")->assertForbidden();
    }

    public function test_a_member_without_any_access_lists_nothing(): void
    {
        $this->caasProject();
        $this->customRole('guest', []);
        $user = $this->userWithRole('guest');

        $this->signedIn($user)->getJson('/api/projects')->assertForbidden();
    }

    public function test_group_project_access_applies_to_its_members(): void
    {
        $project = $this->caasProject();
        $user = $this->userWithRole('viewer');
        $group = $this->groupWithRole('checkout-team', null, [$user]);
        $this->grantProject($group, $project, 'developer');

        $this->assertTrue($this->can($user, 'projects.deploy', $project));
        $this->assertFalse($this->can($this->userWithRole('viewer'), 'projects.deploy', $project), 'only group members');
    }

    public function test_access_is_granted_listed_changed_and_revoked_through_the_api(): void
    {
        $project = $this->caasProject();
        $user = $this->userWithRole('viewer', ['name' => 'Vera']);

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/access", [
            'subject_type' => 'user', 'subject_id' => $user->id, 'role' => 'developer',
        ])->assertCreated();

        $grants = $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}/access")->assertOk();
        $grants->assertJsonPath('grants.0.subject_name', 'Vera')->assertJsonPath('grants.0.role', 'developer')
            ->assertJsonPath('can_manage', true);

        // Same subject again: the role changes, no second grant
        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/access", [
            'subject_type' => 'user', 'subject_id' => $user->id, 'role' => 'viewer',
        ])->assertCreated();
        $this->assertSame(1, RoleAssignment::where('project_id', $project->id)->count());

        $id = RoleAssignment::where('project_id', $project->id)->value('id');
        $this->signedIn($this->owner)->deleteJson("/api/projects/{$project->id}/access/{$id}")->assertOk();
        $this->assertSame(0, RoleAssignment::where('project_id', $project->id)->count());
    }

    public function test_granting_needs_manage_access_and_the_roles_permissions(): void
    {
        $alpha = $this->caasProject('alpha');
        $beta = $this->caasProject('beta');
        $dev = $this->userWithRole('developer');
        $lead = $this->userWithRole('viewer');
        $this->grantProject($lead, $alpha, 'admin');
        $target = $this->userWithRole('viewer');

        $payload = ['subject_type' => 'user', 'subject_id' => $target->id, 'role' => 'developer'];

        // Developer (organization-wide) has no projects.manage_access
        $this->signedIn($dev)->postJson("/api/projects/{$alpha->id}/access", $payload)->assertForbidden();
        // Project admin of alpha grants on alpha, not on beta
        $this->signedIn($lead)->postJson("/api/projects/{$alpha->id}/access", $payload)->assertCreated();
        $this->signedIn($lead)->postJson("/api/projects/{$beta->id}/access", $payload)->assertForbidden();
        // Owner is no project role; unknown subjects and roles are refused
        $this->signedIn($this->owner)->postJson("/api/projects/{$alpha->id}/access", ['role' => 'owner'] + $payload)->assertUnprocessable();
        $this->signedIn($this->owner)->postJson("/api/projects/{$alpha->id}/access", ['subject_id' => 999999] + $payload)->assertUnprocessable();
        $this->signedIn($this->owner)->postJson("/api/projects/{$alpha->id}/access", ['role' => 'nope'] + $payload)->assertUnprocessable();
    }

    public function test_a_creator_without_organization_wide_access_gets_admin_on_the_project(): void
    {
        $this->customRole('maker', ['projects.create']);
        $maker = $this->userWithRole('maker');
        $project = Project::query()->create(['name' => 'made', 'service_type' => 'caas', 'created_by' => $maker->id]);

        $this->assertSame('admin', RoleAssignment::where('project_id', $project->id)->where('subject_id', (string) $maker->id)->value('role'));
        $this->assertTrue($this->can($maker, 'projects.deploy', $project));

        // Users who see every project get no extra grant
        $dev = $this->userWithRole('developer');
        $made = Project::query()->create(['name' => 'made-by-dev', 'service_type' => 'caas', 'created_by' => $dev->id]);
        $this->assertSame(0, RoleAssignment::where('project_id', $made->id)->count());
    }

    public function test_deleting_a_project_removes_its_grants(): void
    {
        $project = $this->caasProject();
        $user = $this->userWithRole('viewer');
        $this->grantProject($user, $project, 'developer');

        DB::table('projects')->where('id', $project->id)->delete();

        $this->assertSame(0, RoleAssignment::where('project_id', $project->id)->count(), 'foreign key cascade');
    }

    // ── Environment variables ───────────────────────────────────────────────

    public function test_environment_variables_need_env_view_on_the_project(): void
    {
        $project = $this->caasProject('shop', ['TOKEN' => 'abc']);
        $this->customRole('guest', []);
        $user = $this->userWithRole('guest');
        $this->grantProject($user, $project, 'viewer');

        $this->signedIn($user)->getJson("/api/projects/{$project->id}")
            ->assertOk()->assertJsonPath('data.config.env_vars_hidden', true)->assertJsonPath('data.config.env_vars', null);

        // Developer on the project: env.view there
        $dev = $this->userWithRole('guest');
        $this->grantProject($dev, $project, 'developer');
        $this->signedIn($dev)->getJson("/api/projects/{$project->id}")
            ->assertOk()->assertJsonPath('data.config.env_vars.TOKEN', 'abc');
    }

    public function test_changing_environment_variables_needs_env_edit(): void
    {
        $project = $this->caasProject('shop', ['TOKEN' => 'abc']);
        $this->customRole('tuner', ['projects.update']);
        $tuner = $this->userWithRole('tuner');

        // Other fields: projects.update is enough
        $this->signedIn($tuner)->putJson("/api/projects/{$project->id}", ['description' => 'tuned'])->assertOk();
        // Changed variables: env.edit
        $this->signedIn($tuner)->putJson("/api/projects/{$project->id}", ['config' => ['env_vars' => ['TOKEN' => 'xyz']]])
            ->assertForbidden();
        $this->assertSame(['TOKEN' => 'abc'], $project->containerConfig->fresh()->env_vars);

        // env.edit alone changes variables, but nothing else
        $this->customRole('env-editor', ['env.edit']);
        $editor = $this->userWithRole('env-editor');
        $this->assertAllowed($this->signedIn($editor)->putJson("/api/projects/{$project->id}", ['config' => ['env_vars' => ['TOKEN' => 'xyz']]]));
        $this->signedIn($editor)->putJson("/api/projects/{$project->id}", ['description' => 'x'])->assertForbidden();
    }

    public function test_creating_a_project_with_environment_variables_needs_env_edit(): void
    {
        $this->customRole('maker', ['projects.create']);
        $maker = $this->userWithRole('maker');

        $this->signedIn($maker)->postJson('/api/projects', [
            'name' => 'withenv', 'service_type' => 'caas', 'config' => ['image' => 'nginx', 'env_vars' => ['A' => '1']],
        ])->assertForbidden();
    }
}
