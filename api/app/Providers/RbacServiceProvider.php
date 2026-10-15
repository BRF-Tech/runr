<?php

namespace App\Providers;

use App\Models\Gateway;
use App\Models\Group;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Policies\GatewayPolicy;
use App\Policies\GroupPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RolePolicy;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\OrganizationResolver;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Roles and permissions (docs/permissions.md): every catalog permission is a Gate ability,
 * platform admins pass every check (Gate::before) unless their API token is scoped, model
 * policies delegate to the same resolver.
 */
class RbacServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(OrganizationResolver::class, OrganizationResolver::class);
        // One resolver per request / queued job (Octane, Horizon); flush() after changes
        $this->app->scoped(Rbac::class);
        $this->app->scoped(AccessManager::class);
    }

    public function boot(): void
    {
        $this->registerGate();
        $this->registerPolicies();
        $this->registerSingleTenantMembership();
        $this->registerProjectCreatorAccess();
    }

    private function registerGate(): void
    {
        Gate::before(function (?Authenticatable $user) {
            $rbac = app(Rbac::class);

            // Platform super-admin — a scoped API token still only carries its abilities
            if ($user && $rbac->isPlatformAdmin($user) && $rbac->tokenAbilities($user) === null) {
                return true;
            }

            return null;
        });

        foreach (PermissionCatalog::allKeys() as $permission) {
            Gate::define($permission, fn (?Authenticatable $user, mixed $scope = null) => app(Rbac::class)->allows($user, $permission, $scope));
        }
    }

    private function registerPolicies(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Gateway::class, GatewayPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);
    }

    /**
     * Single-tenant: a new user gets a role right away — the first user of the install becomes
     * Owner, everyone else the default role. Deleting a user drops their assignments.
     * (Multi-tenant installs keep the role on the tenant membership.)
     */
    private function registerSingleTenantMembership(): void
    {
        User::created(function (User $user) {
            $org = app(OrganizationResolver::class)->current();
            if (! $org instanceof SingleTenantOrganization) {
                return;
            }
            app(AccessManager::class)->onboardSingleTenantUser($user);
        });

        User::deleted(function (User $user) {
            if (app(OrganizationResolver::class)->current() instanceof SingleTenantOrganization) {
                app(AccessManager::class)->forgetUser($user->getKey());
            }
        });
    }

    /**
     * Whoever creates a project but cannot see every project gets the Admin role on it, so a
     * project-scoped member keeps access to what they create.
     */
    private function registerProjectCreatorAccess(): void
    {
        Project::created(function (Project $project) {
            if ($project->created_by) {
                app(AccessManager::class)->grantCreatorAccess($project);
            }
        });
    }
}
