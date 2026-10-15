<?php

namespace App\Support\Rbac;

use App\Models\Group;
use App\Models\Project;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\CacheVersionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Changes to roles, groups and project access — with the escalation guards: nobody hands out
 * (or takes away) a role with permissions they do not hold themselves, the Owner role is granted
 * and revoked by owners only, and the last owner keeps it.
 */
class AccessManager
{
    public const SETTING_DEFAULT_ROLE = 'rbac_default_role';

    public function __construct(private readonly Rbac $rbac) {}

    // ── Default role ────────────────────────────────────────────────────────

    public function defaultRole(): string
    {
        $role = Setting::get(self::SETTING_DEFAULT_ROLE) ?: config('permissions.default_role', 'developer');

        return $role !== PermissionCatalog::OWNER && $this->rbac->roleExists($role) ? $role : 'developer';
    }

    public function setDefaultRole(Authenticatable $actor, string $role): void
    {
        $this->assertAssignableRole($role);
        $this->assertCanGrant($actor, $role);

        Setting::set(self::SETTING_DEFAULT_ROLE, $role);
    }

    // ── Members ─────────────────────────────────────────────────────────────

    /** Single-tenant: the first user of the install becomes Owner, later users the default role. */
    public function onboardSingleTenantUser(User $user, ?string $role = null): void
    {
        $org = new SingleTenantOrganization;

        try {
            if ($org->memberRole($user->getKey())) {
                return;
            }
            $first = ! User::query()->whereKeyNot($user->getKey())->exists();
            $org->setMemberRole($user->getKey(), $first ? PermissionCatalog::OWNER : ($role ?? $this->defaultRole()));
        } catch (QueryException $e) {
            report($e); // RBAC tables not migrated yet: the upgrade migration assigns the role
        }

        $this->changed();
    }

    public function setMemberRole(Authenticatable $actor, int|string $userId, string $role): void
    {
        $org = $this->rbac->organization();
        if (! $org->isMember($userId)) {
            throw ValidationException::withMessages(['user' => __('rbac.not_a_member')]);
        }
        if ((string) $actor->getAuthIdentifier() === (string) $userId) {
            throw ValidationException::withMessages(['role' => __('rbac.cannot_change_own_role')]);
        }
        if (! $this->rbac->roleExists($role)) {
            throw ValidationException::withMessages(['role' => __('rbac.unknown_role')]);
        }

        $current = $org->memberRole($userId);
        if ($current === $role) {
            return;
        }

        $this->assertCanGrant($actor, $role);
        if ($current !== null) {
            $this->assertCanGrant($actor, $current);
        }
        if ($current === PermissionCatalog::OWNER && $org->countMembersWithRole(PermissionCatalog::OWNER) <= 1) {
            throw ValidationException::withMessages(['role' => __('rbac.last_owner')]);
        }

        $org->setMemberRole($userId, $role);
        $this->changed();
    }

    /** May $userId leave / be removed? The last owner stays. */
    public function assertNotLastOwner(int|string $userId): void
    {
        $org = $this->rbac->organization();
        if ($org->memberRole($userId) === PermissionCatalog::OWNER && $org->countMembersWithRole(PermissionCatalog::OWNER) <= 1) {
            throw ValidationException::withMessages(['user' => __('rbac.last_owner')]);
        }
    }

    /** May $actor change or remove the member $userId (their role is one the actor could grant)? */
    public function assertCanManageMember(Authenticatable $actor, int|string $userId): void
    {
        $current = $this->rbac->organization()->memberRole($userId);
        if ($current !== null) {
            $this->assertCanGrant($actor, $current);
        }
    }

    /** Drop every group membership and role assignment of a user (user deleted / left the tenant). */
    public function forgetUser(int|string $userId): void
    {
        try {
            DB::table('rbac_group_user')->where('user_id', $userId)->delete();
            RoleAssignment::query()
                ->where('subject_type', RoleAssignment::SUBJECT_USER)
                ->where('subject_id', (string) $userId)
                ->delete();
        } catch (QueryException $e) {
            report($e);
        }
        $this->changed();
    }

    // ── Groups ──────────────────────────────────────────────────────────────

    public function setGroupRole(Authenticatable $actor, Group $group, ?string $role): void
    {
        $current = $group->orgRole();
        if ($current === $role) {
            return;
        }
        if ($role !== null) {
            $this->assertAssignableRole($role);
            $this->assertCanGrant($actor, $role);
        }
        if ($current !== null) {
            $this->assertCanGrant($actor, $current);
        }

        $query = RoleAssignment::query()
            ->where('subject_type', RoleAssignment::SUBJECT_GROUP)
            ->where('subject_id', $group->id)
            ->whereNull('project_id');

        $role === null
            ? $query->delete()
            : RoleAssignment::query()->updateOrCreate(
                ['subject_type' => RoleAssignment::SUBJECT_GROUP, 'subject_id' => $group->id, 'project_id' => null],
                ['role' => $role],
            );

        $this->changed();
    }

    /** Adding someone to a group hands them the group's roles: the actor must be able to grant those. */
    public function assertCanManageGroupMembers(Authenticatable $actor, Group $group): void
    {
        $assignments = RoleAssignment::query()
            ->where('subject_type', RoleAssignment::SUBJECT_GROUP)
            ->where('subject_id', $group->id)
            ->get(['role', 'project_id']);

        foreach ($assignments as $assignment) {
            $this->assertCanGrant($actor, $assignment->role, $assignment->project_id);
        }
    }

    /** @param list<int|string> $userIds */
    public function addGroupMembers(Group $group, array $userIds, string $source = Group::SOURCE_MANUAL): void
    {
        $org = $this->rbac->organization();
        foreach (array_unique($userIds) as $userId) {
            if (! $org->isMember($userId)) {
                throw ValidationException::withMessages(['user_ids' => __('rbac.not_a_member')]);
            }
            $exists = DB::table('rbac_group_user')->where('group_id', $group->id)->where('user_id', $userId);
            if ($exists->exists()) {
                // A manual addition makes an SSO-synced membership permanent; SSO never downgrades a manual one
                if ($source === Group::SOURCE_MANUAL) {
                    $exists->update(['source' => Group::SOURCE_MANUAL, 'updated_at' => now()]);
                }

                continue;
            }
            DB::table('rbac_group_user')->insert([
                'group_id' => $group->id,
                'user_id' => $userId,
                'source' => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->changed();
    }

    public function removeGroupMember(Group $group, int|string $userId): void
    {
        DB::table('rbac_group_user')->where('group_id', $group->id)->where('user_id', $userId)->delete();
        $this->changed();
    }

    // ── Project access ──────────────────────────────────────────────────────

    public function grantProjectAccess(Authenticatable $actor, Project $project, string $subjectType, string $subjectId, string $role): RoleAssignment
    {
        $this->assertAssignableRole($role);
        $this->assertSubject($subjectType, $subjectId);
        $this->assertCanGrant($actor, $role, $project->id);

        $existing = RoleAssignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('project_id', $project->id)
            ->first();

        if ($existing) {
            $this->assertCanGrant($actor, $existing->role, $project->id);
            $existing->update(['role' => $role]);
        } else {
            $existing = RoleAssignment::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'project_id' => $project->id,
                'role' => $role,
            ]);
        }

        $this->changed();

        return $existing;
    }

    public function revokeProjectAccess(Authenticatable $actor, RoleAssignment $assignment): void
    {
        $this->assertCanGrant($actor, $assignment->role, $assignment->project_id);
        $assignment->delete();
        $this->changed();
    }

    /** The creator of a project who cannot see every project gets the Admin role on it. */
    public function grantCreatorAccess(Project $project): void
    {
        try {
            $creator = User::query()->find($project->created_by);
            if (! $creator || $this->rbac->isPlatformAdmin($creator) || isset($this->rbac->orgPermissions($creator)['projects.view'])) {
                return;
            }
            RoleAssignment::query()->firstOrCreate(
                ['subject_type' => RoleAssignment::SUBJECT_USER, 'subject_id' => (string) $creator->getKey(), 'project_id' => $project->id],
                ['role' => 'admin'],
            );
        } catch (QueryException $e) {
            report($e);
        }
        $this->changed();
    }

    // ── Custom roles ────────────────────────────────────────────────────────

    public function createRole(Authenticatable $actor, array $data): Role
    {
        $permissions = $this->validPermissions($data['permissions'] ?? []);
        $this->assertHolds($actor, $permissions);

        $key = Str::slug($data['key'] ?? $data['name']);
        if ($key === '' || in_array($key, PermissionCatalog::reservedRoleKeys(), true) || Role::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['name' => __('rbac.role_key_taken')]);
        }

        $role = Role::query()->create([
            'key' => $key,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $permissions,
        ]);
        $this->changed();

        return $role;
    }

    public function updateRole(Authenticatable $actor, Role $role, array $data): Role
    {
        $this->assertHolds($actor, PermissionCatalog::expand($role->permissions ?? []));

        $changes = array_intersect_key($data, array_flip(['name', 'description']));
        if (array_key_exists('permissions', $data)) {
            $changes['permissions'] = $this->validPermissions($data['permissions'] ?? []);
            $this->assertHolds($actor, $changes['permissions']);
        }

        $role->update($changes);
        $this->changed();

        return $role;
    }

    /** Members holding the role fall back to the default role; group / project bindings go away. */
    public function deleteRole(Authenticatable $actor, Role $role): void
    {
        $this->assertHolds($actor, PermissionCatalog::expand($role->permissions ?? []));

        DB::transaction(function () use ($role) {
            $fallback = $this->defaultRole();
            if ($fallback === $role->key) {
                $fallback = 'developer';
                Setting::set(self::SETTING_DEFAULT_ROLE, $fallback);
            }
            $this->rbac->organization()->replaceRole($role->key, $fallback);
            RoleAssignment::query()->where('role', $role->key)
                ->where(fn ($q) => $q->where('subject_type', RoleAssignment::SUBJECT_GROUP)->orWhereNotNull('project_id'))
                ->delete();
            if (DB::getSchemaBuilder()->hasTable('sso_group_mappings')) {
                DB::table('sso_group_mappings')->where('role', $role->key)->update(['role' => null]);
            }
            $role->delete();
        });
        $this->changed();
    }

    /** How many members, groups and project grants use a role. */
    public function roleUsage(string $role): array
    {
        return [
            'members' => $this->rbac->organization()->countMembersWithRole($role),
            'groups' => RoleAssignment::query()->where('role', $role)->where('subject_type', RoleAssignment::SUBJECT_GROUP)->whereNull('project_id')->count(),
            'projects' => RoleAssignment::query()->where('role', $role)->whereNotNull('project_id')->count(),
        ];
    }

    /** Forget memoized permissions; cached API responses (per user) are rebuilt. */
    public function changed(): void
    {
        $this->rbac->flush();
        try {
            app(CacheVersionService::class)->bumpMany(['projects', 'settings']);
        } catch (\Throwable) {
            // cache unavailable: responses expire on their own
        }
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    public function assertCanGrant(Authenticatable $actor, string $role, ?string $projectId = null): void
    {
        if (! $this->rbac->canGrantRole($actor, $role, $projectId)) {
            throw new AccessDeniedHttpException(
                $role === PermissionCatalog::OWNER ? __('rbac.owner_only') : __('rbac.cannot_grant_role', ['role' => $role])
            );
        }
    }

    /** A role that may be given to groups, project access, SSO mappings and new members (not Owner). */
    public function assertAssignableRole(string $role): void
    {
        if ($role === PermissionCatalog::OWNER) {
            throw ValidationException::withMessages(['role' => __('rbac.owner_not_assignable')]);
        }
        if (! $this->rbac->roleExists($role)) {
            throw ValidationException::withMessages(['role' => __('rbac.unknown_role')]);
        }
    }

    /** @param list<string> $permissions */
    private function assertHolds(Authenticatable $actor, array $permissions): void
    {
        if (! $this->rbac->holdsAll($actor, $permissions)) {
            throw new AccessDeniedHttpException(__('rbac.cannot_grant_permissions'));
        }
    }

    /** @return list<string> */
    private function validPermissions(array $permissions): array
    {
        $unknown = array_diff($permissions, PermissionCatalog::keys());
        if ($unknown) {
            throw ValidationException::withMessages(['permissions' => __('rbac.unknown_permissions', ['permissions' => implode(', ', $unknown)])]);
        }

        return PermissionCatalog::expand($permissions);
    }

    private function assertSubject(string $type, string $id): void
    {
        $valid = match ($type) {
            RoleAssignment::SUBJECT_USER => $this->rbac->organization()->isMember($id),
            RoleAssignment::SUBJECT_GROUP => Group::query()->whereKey($id)->exists(),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['subject_id' => __('rbac.unknown_subject')]);
        }
    }
}
