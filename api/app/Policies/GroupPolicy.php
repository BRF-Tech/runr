<?php

namespace App\Policies;

use App\Models\Group;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;

/** Groups: members.view lists them, groups.manage changes them (roles need roles.manage too). */
class GroupPolicy
{
    public function __construct(private readonly Rbac $rbac) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->rbac->allows($user, 'members.view');
    }

    public function view(Authenticatable $user, Group $group): bool
    {
        return $this->rbac->allows($user, 'members.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->rbac->allows($user, 'groups.manage');
    }

    public function update(Authenticatable $user, Group $group): bool
    {
        return $this->rbac->allows($user, 'groups.manage');
    }

    public function delete(Authenticatable $user, Group $group): bool
    {
        return $this->rbac->allows($user, 'groups.manage');
    }

    public function assignRole(Authenticatable $user, Group $group): bool
    {
        return $this->rbac->allows($user, 'groups.manage') && $this->rbac->allows($user, 'roles.manage');
    }
}
