<?php

namespace Modules\Tenancy\Support;

use App\Models\User;
use App\Support\Rbac\Contracts\Organization;
use Illuminate\Support\Collection;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * A tenant as RBAC organization: membership and the member's own organization-wide role are the
 * central tenant_users row (role = built-in or custom role key). Groups, custom roles and
 * project access live in the tenant's schema.
 */
class TenantOrganization implements Organization
{
    public function __construct(private readonly Tenant $tenant) {}

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function key(): string
    {
        return 'tenant:'.$this->tenant->getTenantKey();
    }

    public function exists(): bool
    {
        return true;
    }

    public function isMember(int|string $userId): bool
    {
        return $this->memberRole($userId) !== null;
    }

    public function memberRole(int|string $userId): ?string
    {
        return TenantUser::query()
            ->where('tenant_id', $this->tenant->getTenantKey())
            ->where('user_id', $userId)
            ->value('role');
    }

    public function setMemberRole(int|string $userId, string $role): void
    {
        TenantUser::query()
            ->where('tenant_id', $this->tenant->getTenantKey())
            ->where('user_id', $userId)
            ->update(['role' => $role, 'updated_at' => now()]);
    }

    /** The membership row also syncs the user into the tenant schema (TenantUser::created). */
    public function addMember(int|string $userId, string $role): void
    {
        TenantUser::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->getTenantKey(), 'user_id' => $userId],
            ['role' => $role],
        );
    }

    /** Accounts are central; the tenant schema holds synced copies. */
    public function userConnection(): ?string
    {
        return config('tenancy.database.central_connection', 'central');
    }

    public function members(): Collection
    {
        $roles = TenantUser::query()->where('tenant_id', $this->tenant->getTenantKey())->pluck('role', 'user_id');

        return User::on('central')->whereIn('id', $roles->keys())->orderBy('name')->get()
            ->map(fn (User $u) => (object) [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'username' => $u->username,
                'role' => $roles[$u->id] ?? null,
            ]);
    }

    public function countMembersWithRole(string $role): int
    {
        return TenantUser::query()->where('tenant_id', $this->tenant->getTenantKey())->where('role', $role)->count();
    }

    public function replaceRole(string $role, string $replacement): void
    {
        TenantUser::query()->where('tenant_id', $this->tenant->getTenantKey())->where('role', $role)
            ->update(['role' => $replacement, 'updated_at' => now()]);
    }
}
