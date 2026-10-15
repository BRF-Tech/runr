<?php

namespace Tests\Feature\Rbac;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Users the previous version created while an upgrade rolled out (after the role migration ran,
 * before its pods were replaced) have no role; the bootstrap job's rbac:assign-missing gives them
 * the default role so they are not locked out.
 */
class AssignMissingRolesCommandTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private function role(User $user): ?string
    {
        return (new SingleTenantOrganization)->memberRole($user->id);
    }

    public function test_users_without_a_role_get_the_default_role(): void
    {
        $owner = User::factory()->create(['username' => 'admin']);
        $member = User::factory()->create(['username' => 'dev']);
        (new SingleTenantOrganization)->setMemberRole($member->id, 'viewer');
        // created by the previous version during the rollout: no RBAC hook ran
        $late = User::factory()->create(['username' => 'late']);
        RoleAssignment::query()->where('subject_id', (string) $late->id)->delete();
        $this->flushRbac();
        $this->assertNull($this->role($late));
        $this->assertFalse($this->can($late, 'projects.view'));

        $this->artisan('rbac:assign-missing')
            ->expectsOutputToContain('Assigned a role to 1 user(s) without one.')
            ->assertSuccessful();
        $this->flushRbac();

        $this->assertSame('developer', $this->role($late));
        $this->assertTrue($this->can($late, 'projects.view'));
        $this->assertSame('owner', $this->role($owner), 'unchanged');
        $this->assertSame('viewer', $this->role($member), 'unchanged');

        $this->artisan('rbac:assign-missing')
            ->expectsOutputToContain('Assigned a role to 0 user(s) without one.')
            ->assertSuccessful();
    }

    public function test_multi_tenant_installs_are_left_alone(): void
    {
        $user = User::factory()->create();
        RoleAssignment::query()->delete();
        config(['tenancy.enabled' => true]);

        $this->artisan('rbac:assign-missing')->assertSuccessful();

        $this->assertSame(0, RoleAssignment::query()->count());
        $this->assertNull($this->role($user));
    }
}
