<?php

namespace App\Support\Rbac;

/**
 * Read access to config/permissions.php: the permission catalog and the built-in roles of the
 * running edition (single-tenant or multi-tenant).
 */
class PermissionCatalog
{
    public const WILDCARD = '*';

    public const OWNER = 'owner';

    /** multi-tenant when the Tenancy module is on, single-tenant otherwise */
    public static function edition(): string
    {
        return config('tenancy.enabled') ? 'multi' : 'single';
    }

    /** @return array<string, array{group: string, scope: string, edition?: string, implies?: list<string>}> */
    public static function permissions(): array
    {
        $edition = self::edition();

        return array_filter(
            config('permissions.permissions', []),
            fn (array $meta) => in_array($meta['edition'] ?? 'all', ['all', $edition], true),
        );
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::permissions());
    }

    /** Every permission name of every edition (Gate abilities are defined for all of them). */
    public static function allKeys(): array
    {
        return array_keys(config('permissions.permissions', []));
    }

    public static function exists(string $permission): bool
    {
        return isset(self::permissions()[$permission]);
    }

    public static function isProjectScoped(string $permission): bool
    {
        return (self::permissions()[$permission]['scope'] ?? null) === 'project';
    }

    /** @return list<string> */
    public static function projectScoped(): array
    {
        return array_keys(array_filter(self::permissions(), fn (array $m) => $m['scope'] === 'project'));
    }

    /**
     * Normalize a permission list: '*' → every permission of the edition, implied permissions
     * added, unknown names (and other editions' permissions) dropped.
     *
     * @param  iterable<string>  $permissions
     * @return list<string>
     */
    public static function expand(iterable $permissions): array
    {
        $catalog = self::permissions();
        $result = [];
        $queue = [];

        foreach ($permissions as $permission) {
            if ($permission === self::WILDCARD) {
                return array_keys($catalog);
            }
            $queue[] = $permission;
        }

        while ($queue) {
            $permission = array_pop($queue);
            if (! isset($catalog[$permission]) || isset($result[$permission])) {
                continue;
            }
            $result[$permission] = true;
            foreach ($catalog[$permission]['implies'] ?? [] as $implied) {
                $queue[] = $implied;
            }
        }

        // Catalog order (stable output for the API and the UI)
        return array_values(array_filter(array_keys($catalog), fn ($key) => isset($result[$key])));
    }

    /**
     * Built-in roles of the edition: key => [name, description, permissions (expanded), builtin].
     *
     * @return array<string, array{key: string, name: string, description: string, permissions: list<string>, builtin: bool}>
     */
    public static function builtinRoles(): array
    {
        $edition = self::edition();
        $roles = [];

        foreach (config('permissions.roles', []) as $key => $role) {
            if (! in_array($role['edition'] ?? 'all', ['all', $edition], true)) {
                continue;
            }
            $roles[$key] = [
                'key' => $key,
                'name' => $role['name'] ?? ucfirst($key),
                'description' => $role['description'] ?? '',
                'permissions' => self::expand($role['permissions'] ?? []),
                'builtin' => true,
            ];
        }

        return $roles;
    }

    public static function isBuiltinRole(string $key): bool
    {
        return isset(self::builtinRoles()[$key]);
    }

    /** Built-in role keys of any edition (custom roles may not take these keys). */
    public static function reservedRoleKeys(): array
    {
        return array_keys(config('permissions.roles', []));
    }

    /**
     * Token abilities of 0.4.x (projects:read …) → permissions; other values are kept.
     *
     * @param  iterable<string>  $abilities
     * @return list<string>
     */
    public static function translateLegacyAbilities(iterable $abilities): array
    {
        $legacy = config('permissions.legacy_token_abilities', []);
        $result = [];

        foreach ($abilities as $ability) {
            foreach ($legacy[$ability] ?? [$ability] as $permission) {
                $result[$permission] = true;
            }
        }

        return array_keys($result);
    }
}
