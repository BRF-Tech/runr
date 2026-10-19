<?php

namespace Modules\Tenancy\Services;

use Illuminate\Support\Facades\Artisan;
use Modules\Tenancy\Models\Tenant;
use SharedSupport\TenantMigrationRegistry;

/**
 * The one way a tenant's database (a PostgreSQL schema "tenant_<tenant id>") is created and
 * migrated: Tenancy's TenantMigrations plus the tenant migrations other modules register
 * (TenantMigrationRegistry, e.g. the Ticket module). Every path that creates or migrates a
 * tenant goes through here — the console (POST /api/tenants), self-service signups,
 * tenancy:create-tenant, tenancy:migrate, tenancy:setup and the development seeder — so a
 * tenant never misses a module's migrations and the next tenancy:migrate finds nothing it ran
 * differently.
 */
class TenantSchema
{
    /**
     * Migration folders of a tenant database.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        return array_values(array_unique(array_merge(
            [module_path('Tenancy', 'Database/TenantMigrations')],
            TenantMigrationRegistry::paths(),
        )));
    }

    /**
     * Options of `migrate` for a tenant database (config tenancy.migration_parameters is kept in
     * step once every module has booted, TenancyServiceProvider).
     *
     * @return array{'--path': list<string>, '--realpath': true, '--force': true}
     */
    public static function migrationParameters(): array
    {
        return [
            '--path' => self::paths(),
            '--realpath' => true,
            '--force' => true,
        ];
    }

    public static function exists(Tenant $tenant): bool
    {
        return $tenant->database()->manager()->databaseExists($tenant->database()->getName());
    }

    /**
     * Creates the tenant's database unless it exists, then runs its pending migrations.
     *
     * @return string output of `migrate`
     */
    public static function create(Tenant $tenant): string
    {
        if (! self::exists($tenant)) {
            $tenant->database()->manager()->createDatabase($tenant);
        }

        return self::migrate($tenant);
    }

    /**
     * Pending migrations of the tenant's database (run in its context).
     *
     * @return string output of `migrate`
     */
    public static function migrate(Tenant $tenant): string
    {
        return self::inTenant($tenant, function () {
            Artisan::call('migrate', self::migrationParameters());

            return trim(Artisan::output());
        });
    }

    /**
     * Drops every table of the tenant's database — and only of that one — and migrates it again.
     * `migrate:fresh` cannot do this: in tenant context the search path is "tenant_<id>, public",
     * and Laravel's dropAllTables() drops the tables of every schema on it, the central ones too.
     * The schema is dropped and created again instead (SQLite: the database file).
     *
     * @return string output of `migrate`
     */
    public static function fresh(Tenant $tenant): string
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $manager = $tenant->database()->manager();
        if (self::exists($tenant)) {
            $manager->deleteDatabase($tenant);
        }
        $manager->createDatabase($tenant);

        return self::migrate($tenant);
    }

    /**
     * $callback in the tenant's context; the previous context comes back even when it throws
     * (stancl's Tenant::run() leaves the tenant initialized then).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function inTenant(Tenant $tenant, callable $callback): mixed
    {
        $previous = tenancy()->initialized ? tenancy()->tenant : null;

        tenancy()->initialize($tenant);
        try {
            return $callback();
        } finally {
            if ($previous) {
                tenancy()->initialize($previous);
            } else {
                tenancy()->end();
            }
        }
    }
}
