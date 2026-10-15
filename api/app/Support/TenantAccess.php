<?php

namespace App\Support;

use Closure;

/**
 * The current tenant and whether a user is a member of it, for code that must work in both
 * editions (audit log scoping, impersonation). Single-tenant: no tenant. The Tenancy module
 * registers the resolvers (TenancyServiceOverridesTrait::registerTenantAccess). Permissions are
 * RBAC's (App\Support\Rbac\Rbac).
 */
final class TenantAccess
{
    /** fn (): ?array{id: string, name: ?string, slug: ?string} */
    private static ?Closure $currentResolver = null;

    /** fn (mixed $user, string $tenantId): ?string role (owner / admin / member / viewer) */
    private static ?Closure $roleResolver = null;

    public static function setResolvers(?Closure $current, ?Closure $role): void
    {
        self::$currentResolver = $current;
        self::$roleResolver = $role;
    }

    /** @return array{id: string, name: ?string, slug: ?string}|null */
    public static function current(): ?array
    {
        if (! self::$currentResolver) {
            return null;
        }

        try {
            $tenant = (self::$currentResolver)();
        } catch (\Throwable) {
            return null;
        }

        return is_array($tenant) && ! empty($tenant['id']) ? $tenant : null;
    }

    public static function currentId(): ?string
    {
        return self::current()['id'] ?? null;
    }

    /** The user's role in $tenantId (default: the current tenant); null = not a member. */
    public static function roleOf(mixed $user, ?string $tenantId = null): ?string
    {
        $tenantId ??= self::currentId();
        if (! $user || ! $tenantId || ! self::$roleResolver) {
            return null;
        }

        try {
            $role = (self::$roleResolver)($user, $tenantId);
        } catch (\Throwable) {
            return null;
        }

        return is_string($role) && $role !== '' ? $role : null;
    }
}
