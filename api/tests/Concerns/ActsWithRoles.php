<?php

namespace Tests\Concerns;

use App\Models\ContainerConfig;
use App\Models\Group;
use App\Models\Project;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Users with roles, groups and project access for the RBAC tests (single-tenant organization;
 * the suite runs with TENANCY_ENABLED off). The first user of an install is its Owner, so tests
 * create the owner first.
 */
trait ActsWithRoles
{
    protected function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['username' => 'u'.Str::lower(Str::random(10))], $attributes));
        (new SingleTenantOrganization)->setMemberRole($user->id, $role);
        $this->flushRbac();

        return $user;
    }

    protected function platformAdmin(): User
    {
        // users.is_admin comes with the Tenancy module's migrations (off in this suite)
        if (! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('is_admin')->default(false));
        }
        $user = $this->userWithRole('viewer');
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    /** @param list<string> $permissions */
    protected function customRole(string $key, array $permissions, ?string $name = null): Role
    {
        $role = Role::query()->create([
            'key' => $key,
            'name' => $name ?? Str::headline($key),
            'permissions' => PermissionCatalog::expand($permissions),
        ]);
        $this->flushRbac();

        return $role;
    }

    protected function grantProject(User|Group $subject, Project $project, string $role): RoleAssignment
    {
        $assignment = RoleAssignment::query()->create([
            'subject_type' => $subject instanceof Group ? RoleAssignment::SUBJECT_GROUP : RoleAssignment::SUBJECT_USER,
            'subject_id' => (string) $subject->getKey(),
            'project_id' => $project->id,
            'role' => $role,
        ]);
        $this->flushRbac();

        return $assignment;
    }

    protected function groupWithRole(string $name, ?string $role, array $members = []): Group
    {
        $group = Group::query()->create(['name' => $name]);
        if ($role) {
            RoleAssignment::query()->create([
                'subject_type' => RoleAssignment::SUBJECT_GROUP,
                'subject_id' => $group->id,
                'project_id' => null,
                'role' => $role,
            ]);
        }
        foreach ($members as $member) {
            DB::table('rbac_group_user')->insert([
                'group_id' => $group->id, 'user_id' => $member->id, 'source' => Group::SOURCE_MANUAL,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->flushRbac();

        return $group;
    }

    protected function caasProject(string $name = 'shop', array $env = ['GREETING' => 'hello']): Project
    {
        $project = Project::query()->create(['name' => $name, 'service_type' => 'caas']);
        ContainerConfig::query()->create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
            'env_vars' => $env,
        ]);

        return $project->fresh();
    }

    protected function can(User $user, string $permission, mixed $scope = null): bool
    {
        $this->flushRbac();

        return app(Rbac::class)->allows($user, $permission, $scope);
    }

    protected function flushRbac(): void
    {
        app(Rbac::class)->flush();
    }

    /** A different user per request: forget the guard's cached user. */
    protected function signedIn(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    protected function assertAllowed(TestResponse $response, string $message = ''): TestResponse
    {
        $this->assertNotSame(403, $response->status(), $message ?: 'expected the request to pass authorization: '.$response->getContent());
        $this->assertNotSame(401, $response->status(), $message ?: 'expected an authenticated request');

        return $response;
    }
}
