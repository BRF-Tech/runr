<?php

namespace App\Policies;

use App\Models\Project;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Project abilities → catalog permissions, checked for the project (organization-wide role or
 * project access). $user->can('deploy', $project) ≡ $user->can('projects.deploy', $project).
 */
class ProjectPolicy
{
    public function __construct(private readonly Rbac $rbac) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->rbac->allowsAnywhere($user, 'projects.view');
    }

    public function view(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'projects.view', $project);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->rbac->allows($user, 'projects.create');
    }

    public function update(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'projects.update', $project);
    }

    public function delete(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'projects.delete', $project);
    }

    public function deploy(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'projects.deploy', $project);
    }

    public function editCode(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'code.edit', $project);
    }

    public function viewEnv(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'env.view', $project);
    }

    public function editEnv(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'env.edit', $project);
    }

    public function viewSecretValues(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'secrets.view_values', $project);
    }

    public function manageDomains(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'domains.manage', $project);
    }

    public function manageConnections(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'connections.manage', $project);
    }

    public function exec(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'shell.exec', $project);
    }

    public function viewLogs(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'logs.view', $project);
    }

    public function viewMetrics(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'metrics.view', $project);
    }

    public function manageAccess(Authenticatable $user, Project $project): bool
    {
        return $this->rbac->allows($user, 'projects.manage_access', $project);
    }
}
