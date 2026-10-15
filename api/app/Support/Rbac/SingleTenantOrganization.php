<?php

namespace App\Support\Rbac;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Rbac\Contracts\Organization;
use Illuminate\Support\Collection;

/**
 * Single-tenant install: every user is a member; the own organization-wide role is a
 * role assignment without project (subject user).
 */
class SingleTenantOrganization implements Organization
{
    public function key(): string
    {
        return 'single';
    }

    public function exists(): bool
    {
        return true;
    }

    public function isMember(int|string $userId): bool
    {
        return User::query()->whereKey($userId)->exists();
    }

    public function memberRole(int|string $userId): ?string
    {
        return $this->ownAssignments()->where('subject_id', (string) $userId)->value('role');
    }

    public function setMemberRole(int|string $userId, string $role): void
    {
        RoleAssignment::query()->updateOrCreate(
            ['subject_type' => RoleAssignment::SUBJECT_USER, 'subject_id' => (string) $userId, 'project_id' => null],
            ['role' => $role],
        );
    }

    public function addMember(int|string $userId, string $role): void
    {
        $this->setMemberRole($userId, $role);
    }

    public function userConnection(): ?string
    {
        return null;
    }

    public function members(): Collection
    {
        $roles = $this->ownAssignments()->pluck('role', 'subject_id');

        return User::query()->orderBy('name')->get()->map(fn (User $u) => (object) [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'username' => $u->username,
            'role' => $roles[(string) $u->id] ?? null,
        ]);
    }

    public function countMembersWithRole(string $role): int
    {
        return $this->ownAssignments()->where('role', $role)->count();
    }

    public function replaceRole(string $role, string $replacement): void
    {
        $this->ownAssignments()->where('role', $role)->update(['role' => $replacement]);
    }

    private function ownAssignments()
    {
        return RoleAssignment::query()
            ->where('subject_type', RoleAssignment::SUBJECT_USER)
            ->whereNull('project_id');
    }
}
