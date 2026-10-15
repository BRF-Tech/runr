<?php

namespace App\Policies;

use App\Models\Role;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;

/** Custom roles: anyone signed in sees them (role pickers); roles.manage changes them. */
class RolePolicy
{
    public function __construct(private readonly Rbac $rbac) {}

    public function view(Authenticatable $user, Role $role): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return $this->rbac->allows($user, 'roles.manage');
    }

    public function update(Authenticatable $user, Role $role): bool
    {
        return $this->rbac->allows($user, 'roles.manage');
    }

    public function delete(Authenticatable $user, Role $role): bool
    {
        return $this->rbac->allows($user, 'roles.manage');
    }
}
