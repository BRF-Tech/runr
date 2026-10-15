<?php

namespace SharedSupport;

/**
 * Registry for tenant migration paths.
 *
 * Base tenancy command only knows its own migration path.
 * Other modules (e.g. Ticket) register their tenant migration paths here
 * so tenancy:migrate picks them up without coupling to app/.
 */
class TenantMigrationRegistry
{
    private static array $paths = [];

    public static function register(string $path): void
    {
        self::$paths[] = $path;
    }

    /** @return array<string> */
    public static function paths(): array
    {
        return self::$paths;
    }
}
