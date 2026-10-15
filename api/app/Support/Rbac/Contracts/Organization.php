<?php

namespace App\Support\Rbac\Contracts;

use Illuminate\Support\Collection;

/**
 * The organization a request runs in and where its members' own organization-wide roles live:
 * the install itself in single-tenant mode, the active tenant in multi-tenant mode. Groups,
 * custom roles and project access are stored in the organization's database either way.
 */
interface Organization
{
    /** Stable cache key of this organization ("single", "tenant:<id>", "none"). */
    public function key(): string;

    /** False in the multi-tenant central context: only platform admins act there. */
    public function exists(): bool;

    public function isMember(int|string $userId): bool;

    /** The member's own organization-wide role key, null when not a member / no role. */
    public function memberRole(int|string $userId): ?string;

    public function setMemberRole(int|string $userId, string $role): void;

    /** Make a user a member with $role (just-in-time SSO provisioning). */
    public function addMember(int|string $userId, string $role): void;

    /** Database connection user accounts of this organization are created on (null = default). */
    public function userConnection(): ?string;

    /** @return Collection<int, object{id: int|string, name: string, email: string, username: ?string, role: ?string}> */
    public function members(): Collection;

    public function countMembersWithRole(string $role): int;

    /** Members holding $role get $replacement (a custom role was deleted). */
    public function replaceRole(string $role, string $replacement): void;
}
