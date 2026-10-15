<?php

namespace App\Support\Rbac;

use App\Support\Rbac\Contracts\Organization;
use Illuminate\Support\Collection;
use RuntimeException;

/** Multi-tenant central context (no active tenant): nobody but platform admins has permissions. */
class NoOrganization implements Organization
{
    public function key(): string
    {
        return 'none';
    }

    public function exists(): bool
    {
        return false;
    }

    public function isMember(int|string $userId): bool
    {
        return false;
    }

    public function memberRole(int|string $userId): ?string
    {
        return null;
    }

    public function setMemberRole(int|string $userId, string $role): void
    {
        throw new RuntimeException('No active organization: roles are assigned inside a tenant.');
    }

    public function addMember(int|string $userId, string $role): void
    {
        throw new RuntimeException('No active organization: members join a tenant.');
    }

    public function userConnection(): ?string
    {
        return null;
    }

    public function members(): Collection
    {
        return collect();
    }

    public function countMembersWithRole(string $role): int
    {
        return 0;
    }

    public function replaceRole(string $role, string $replacement): void {}
}
