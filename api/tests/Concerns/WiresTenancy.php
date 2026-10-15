<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\PostgreSQLSchemaManagerWithPublic;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

/**
 * The suite runs with TENANCY_ENABLED off (SQLite :memory:). This wires just enough of the
 * Tenancy module for $tenant->run() to switch to a real tenant database — the same way
 * TenantTeardownTest does — plus helpers to create a tenant schema from selected tenant
 * migrations. Call setUpTenancy() from setUp() and tearDownTenancy() from tearDown().
 */
trait WiresTenancy
{
    /** @var list<Tenant> */
    private array $wiredTenants = [];

    /** PostgreSQL: setUpTenancy() committed RefreshDatabase's transaction (see there). */
    private bool $tenancyLeftTransaction = false;

    /**
     * Tenant migrations every tenant schema created here gets: knative_regions with every later
     * column (the central → tenant sync writes all of them), and what those migrations alter
     * (0045: the config tables of 0001 and usage_records of 0009).
     */
    private const TENANT_REGION_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0009_create_usage_records_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0022_add_agent_token_hash_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0024_add_function_tls_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0035_add_wasm_sandbox_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0045_add_gpu_columns.php',
        'Modules/Tenancy/Database/TenantMigrations/0052_add_capacity_admission_columns.php',
    ];

    protected function setUpTenancy(): void
    {
        if (! config('database.connections.central')) {
            config(['database.connections.central' => config('database.connections.'.config('database.default'))]);
        }
        // A same-config sqlite ":memory:" connection is a separate database: "central" is the test
        // connection itself — one connection object, so a transaction on the central connection
        // (PlanController, billing) nests in RefreshDatabase's transaction instead of starting a
        // second one on the shared PDO
        $manager = $this->app['db'];
        $connections = new \ReflectionProperty($manager, 'connections');
        $connections->setValue($manager, ['central' => $manager->connection()] + $connections->getValue($manager));

        // PostgreSQL (DB_CONNECTION=pgsql runs): tenant schemas as in an install — and a tenant
        // connection is a second session, which sees committed rows only: the test leaves
        // RefreshDatabase's transaction and tearDownTenancy() starts from a fresh schema again
        if ($manager->connection()->getDriverName() === 'pgsql') {
            config([
                'tenancy.database.managers.pgsql' => PostgreSQLSchemaManagerWithPublic::class,
                'tenancy.database.central_connection' => 'central',
                'tenancy.database.prefix' => 'tenant_',
                'tenancy.database.suffix' => '',
            ]);
            while ($manager->connection()->transactionLevel() > 0) {
                $manager->connection()->commit();
            }
            $this->tenancyLeftTransaction = true;
        }

        // Central-connection module migrations (tenants, tenant_users, tenant_regions) failed in
        // RefreshDatabase's first pass, before 'central' existed — apply them now.
        Artisan::call('migrate', ['--path' => 'Modules/Tenancy/Database/Migrations', '--force' => true]);

        // The bootstrappers of a multi-tenant install (TenancyServiceProvider::overrideStanclDefaults):
        // without stancl's FilesystemTenancyBootstrapper storage_path() and the disks stay the same
        // in tenant context (git repositories, branding assets, plan storage usage)
        config([
            'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class, CacheTenancyBootstrapper::class, QueueTenancyBootstrapper::class],
            'tenancy.filesystem.suffix_storage_path' => false,
        ]);

        if (! Event::hasListeners(TenancyInitialized::class)) {
            Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        }
        if (! Event::hasListeners(TenancyEnded::class)) {
            Event::listen(TenancyEnded::class, RevertToCentralContext::class);
        }
    }

    protected function tearDownTenancy(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->wiredTenants as $tenant) {
            try {
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (\Throwable) {
                // already gone
            }
        }
        $this->wiredTenants = [];

        // Static resolver registered by some tests (TenancyServiceProvider::registerRegionSyncEvents)
        KnativeRegion::setKubeconfigResolver(null);

        // PostgreSQL: the test committed its rows — the next test starts from fresh tables
        if ($this->tenancyLeftTransaction) {
            $this->tenancyLeftTransaction = false;
            config(['ide-helper.post_migrate' => []]);
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    /**
     * Tenant with its own database, migrated with $migrations (default: the region tables).
     *
     * @param  list<string>|null  $migrations
     */
    protected function createTenantWithSchema(string $slug, ?array $migrations = null): Tenant
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
        $tenant->database()->manager()->createDatabase($tenant);
        $this->wiredTenants[] = $tenant;

        $tenant->run(function () use ($migrations) {
            foreach ($migrations ?? self::TENANT_REGION_MIGRATIONS as $migration) {
                (require base_path($migration))->up();
            }
        });

        return $tenant;
    }
}
