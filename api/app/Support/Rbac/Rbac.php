<?php

namespace App\Support\Rbac;

use App\Models\Project;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Support\Rbac\Contracts\Organization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Effective permissions of a user in the current organization (docs/permissions.md):
 *
 *   own organization role ∪ roles of the user's groups            → organization-wide
 *   ∪ project access (user or group, project-scoped permissions)  → on that project
 *   ∩ the API token's abilities (a token never exceeds its owner)
 *
 * Platform admins (users.is_admin) hold every permission — still limited by a scoped token.
 * Results are memoized per request (scoped binding; flush() after changes).
 */
class Rbac
{
    /** @var array<string, array<string, true>> */
    private array $orgPermissions = [];

    /** @var array<string, array<string, array<string, true>>> */
    private array $projectPermissions = [];

    /** @var array<string, list<string>> */
    private array $groupIds = [];

    /** @var array<string, list<string>> */
    private array $rolePermissions = [];

    public function __construct(private readonly OrganizationResolver $resolver) {}

    public function organization(): Organization
    {
        return $this->resolver->current();
    }

    public function flush(): void
    {
        $this->orgPermissions = $this->projectPermissions = $this->groupIds = $this->rolePermissions = [];
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    /**
     * $scope: a Project, a model with project_id, a project id — checked for project access too.
     */
    public function allows(?Authenticatable $user, string $permission, mixed $scope = null): bool
    {
        if (! $user || ! $this->tokenAllows($user, $permission)) {
            return false;
        }
        if ($this->isPlatformAdmin($user)) {
            return true;
        }
        if (isset($this->orgPermissions($user)[$permission])) {
            return true;
        }

        $projectId = $this->projectIdOf($scope);
        if ($projectId === null || ! PermissionCatalog::isProjectScoped($permission)) {
            return false;
        }

        return isset($this->projectPermissions($user)[$projectId][$permission]);
    }

    /** Organization-wide or on at least one project. */
    public function allowsAnywhere(?Authenticatable $user, string $permission): bool
    {
        if (! $user || ! $this->tokenAllows($user, $permission)) {
            return false;
        }
        if ($this->isPlatformAdmin($user) || isset($this->orgPermissions($user)[$permission])) {
            return true;
        }
        foreach ($this->projectPermissions($user) as $permissions) {
            if (isset($permissions[$permission])) {
                return true;
            }
        }

        return false;
    }

    /** Projects the user may see with $permission; null = every project. */
    public function visibleProjectIds(?Authenticatable $user, string $permission = 'projects.view'): ?array
    {
        if (! $user || ! $this->tokenAllows($user, $permission)) {
            return [];
        }
        if ($this->isPlatformAdmin($user) || isset($this->orgPermissions($user)[$permission])) {
            return null;
        }

        return array_keys(array_filter(
            $this->projectPermissions($user),
            fn (array $permissions) => isset($permissions[$permission]),
        ));
    }

    public function isPlatformAdmin(?Authenticatable $user): bool
    {
        return $user instanceof Model && (bool) $user->getAttribute('is_admin');
    }

    public function isOwner(?Authenticatable $user): bool
    {
        return $user !== null
            && $this->organization()->memberRole($user->getAuthIdentifier()) === PermissionCatalog::OWNER;
    }

    // ── Tokens ──────────────────────────────────────────────────────────────

    public function tokenAllows(Authenticatable $user, string $permission): bool
    {
        $abilities = $this->tokenAbilities($user);

        return $abilities === null || in_array($permission, $abilities, true);
    }

    /** Permissions the current API token is limited to; null = not limited (session / '*'). */
    public function tokenAbilities(Authenticatable $user): ?array
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $abilities = (array) ($token->abilities ?? []);
        if (in_array(PermissionCatalog::WILDCARD, $abilities, true)) {
            return null;
        }

        return PermissionCatalog::expand(PermissionCatalog::translateLegacyAbilities($abilities));
    }

    /**
     * What a new token of this user may carry: every permission the user holds organization-wide
     * or on some project (limited by the token the request itself uses).
     *
     * @return list<string>
     */
    public function grantablePermissions(Authenticatable $user): array
    {
        return array_values(array_filter(
            PermissionCatalog::keys(),
            fn (string $permission) => $this->allowsAnywhere($user, $permission),
        ));
    }

    // ── Escalation guards ───────────────────────────────────────────────────

    /**
     * May $actor give $role to someone (organization-wide, or on $projectId)? Only roles whose
     * permissions the actor holds on that scope; the Owner role only organization-wide, by owners.
     */
    public function canGrantRole(Authenticatable $actor, string $role, ?string $projectId = null): bool
    {
        if ($role === PermissionCatalog::OWNER) {
            return $projectId === null
                && (($this->isPlatformAdmin($actor) && $this->tokenAbilities($actor) === null) || $this->isOwner($actor));
        }

        $permissions = $this->rolePermissions($role);
        if ($projectId !== null) {
            $permissions = array_intersect($permissions, PermissionCatalog::projectScoped());
        }

        return $this->holdsAll($actor, $permissions, $projectId);
    }

    /** @param iterable<string> $permissions */
    public function holdsAll(Authenticatable $actor, iterable $permissions, ?string $projectId = null): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->allows($actor, $permission, $projectId)) {
                return false;
            }
        }

        return true;
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    /** @return array<string, true> organization-wide permissions (before the token limit) */
    public function orgPermissions(Authenticatable $user): array
    {
        $org = $this->organization();
        $key = $org->key().'|'.$user->getAuthIdentifier();

        return $this->orgPermissions[$key] ??= $this->resolveOrgPermissions($org, $user);
    }

    /** @return array<string, array<string, true>> project id => project-scoped permissions from project access */
    public function projectPermissions(Authenticatable $user): array
    {
        $org = $this->organization();
        $key = $org->key().'|'.$user->getAuthIdentifier();

        return $this->projectPermissions[$key] ??= $this->resolveProjectPermissions($org, $user);
    }

    /** Organization-wide role keys of the user: own role first, then the groups' roles. */
    public function orgRoles(Authenticatable $user): array
    {
        $org = $this->organization();
        if (! $org->exists()) {
            return [];
        }

        $roles = [];
        if ($own = $org->memberRole($user->getAuthIdentifier())) {
            $roles[] = $own;
        } elseif (! $org->isMember($user->getAuthIdentifier())) {
            return [];
        }

        $groupIds = $this->groupIds($user);
        if ($groupIds) {
            $roles = array_merge($roles, $this->safely(fn () => RoleAssignment::query()
                ->where('subject_type', RoleAssignment::SUBJECT_GROUP)
                ->whereIn('subject_id', $groupIds)
                ->whereNull('project_id')
                ->pluck('role')
                ->all(), []));
        }

        return array_values(array_unique($roles));
    }

    /** @return list<string> */
    public function groupIds(Authenticatable $user): array
    {
        $key = $this->organization()->key().'|'.$user->getAuthIdentifier();

        return $this->groupIds[$key] ??= $this->safely(fn () => DB::table('rbac_group_user')
            ->where('user_id', $user->getAuthIdentifier())
            ->pluck('group_id')
            ->map(fn ($id) => (string) $id)
            ->all(), []);
    }

    /** @return list<string> permissions of a role key (built-in or custom) in this organization */
    public function rolePermissions(string $role): array
    {
        $key = $this->organization()->key().'|'.$role;

        return $this->rolePermissions[$key] ??= $this->resolveRolePermissions($role);
    }

    /** Is $role a role of this organization (built-in or custom)? */
    public function roleExists(string $role): bool
    {
        return PermissionCatalog::isBuiltinRole($role) || $this->customRole($role) !== null;
    }

    public function customRole(string $role): ?Role
    {
        return $this->safely(fn () => Role::query()->where('key', $role)->first(), null);
    }

    private function resolveOrgPermissions(Organization $org, Authenticatable $user): array
    {
        $permissions = [];
        foreach ($this->orgRoles($user) as $role) {
            foreach ($this->rolePermissions($role) as $permission) {
                $permissions[$permission] = true;
            }
        }

        return $permissions;
    }

    private function resolveProjectPermissions(Organization $org, Authenticatable $user): array
    {
        if (! $org->exists() || ! $org->isMember($user->getAuthIdentifier())) {
            return [];
        }

        $groupIds = $this->groupIds($user);
        $rows = $this->safely(fn () => RoleAssignment::query()
            ->whereNotNull('project_id')
            ->where(function ($q) use ($user, $groupIds) {
                $q->where(fn ($q) => $q->where('subject_type', RoleAssignment::SUBJECT_USER)
                    ->where('subject_id', (string) $user->getAuthIdentifier()));
                if ($groupIds) {
                    $q->orWhere(fn ($q) => $q->where('subject_type', RoleAssignment::SUBJECT_GROUP)
                        ->whereIn('subject_id', $groupIds));
                }
            })
            ->get(['project_id', 'role']), collect());

        $scoped = array_flip(PermissionCatalog::projectScoped());
        $result = [];
        foreach ($rows as $row) {
            foreach ($this->rolePermissions($row->role) as $permission) {
                if (isset($scoped[$permission])) {
                    $result[(string) $row->project_id][$permission] = true;
                }
            }
        }

        return $result;
    }

    private function resolveRolePermissions(string $role): array
    {
        $builtin = PermissionCatalog::builtinRoles();
        if (isset($builtin[$role])) {
            return $builtin[$role]['permissions'];
        }

        if ($custom = $this->customRole($role)) {
            return PermissionCatalog::expand($custom->permissions ?? []);
        }

        // Upgraded tenant whose "member" role has not been created (yet): what Developer can do
        if ($role === config('permissions.legacy_member_role.key')) {
            return $builtin['developer']['permissions'] ?? [];
        }

        return [];
    }

    private function projectIdOf(mixed $scope): ?string
    {
        return match (true) {
            $scope instanceof Project => (string) $scope->getKey(),
            $scope instanceof Model && $scope->project_id => (string) $scope->project_id,
            is_string($scope) && $scope !== '' => $scope,
            default => null,
        };
    }

    /**
     * RBAC tables live in the organization's database. A database without them (a tenant schema
     * the upgrade has not migrated yet) counts as "no groups, no custom roles, no project access".
     */
    private function safely(callable $query, mixed $default): mixed
    {
        try {
            return $query();
        } catch (QueryException $e) {
            report($e);

            return $default;
        }
    }
}
