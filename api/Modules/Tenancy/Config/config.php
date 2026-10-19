<?php

declare(strict_types=1);

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Models\RuntimeBuild;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\UUIDGenerator;

return [
    /**
     * Master toggle: TENANCY_ENABLED=true activates multi-tenant features.
     * When false, stancl/tenancy is installed but dormant.
     */
    'enabled' => env('TENANCY_ENABLED', false),

    'tenant_model' => Tenant::class, // Overridden by TenancyServiceProvider when module is active
    'id_generator' => UUIDGenerator::class,

    'domain_model' => Domain::class,

    // Not used — we identify tenants via X-Tenant-ID header, not domains
    'central_domains' => [
        '127.0.0.1',
        'localhost',
    ],

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        // Filesystem bootstrapper disabled — we handle git storage paths manually
    ],

    'database' => [
        'central_connection' => 'central',

        'template_tenant_connection' => null,

        // Schema naming: tenant_{tenant id} — the tenant's UUID, not its slug
        'prefix' => 'tenant_',
        'suffix' => '',

        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            // Schema-per-tenant for PostgreSQL (not separate databases)
            'pgsql' => PostgreSQLSchemaManager::class,
        ],
    ],

    'cache' => [
        'tag_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => ['local', 'public'],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => false,
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:8061'),

    'features' => [],

    'routes' => false,

    // Tenant schema migrations: TenancyServiceProvider replaces this with every module's tenant
    // migration folders (Modules\Tenancy\Services\TenantSchema)
    'migration_parameters' => [
        '--force' => true,
        '--path' => [base_path('Modules/Tenancy/Database/TenantMigrations')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],

    /**
     * Central models: their connection is set to 'central' when tenancy boots.
     */
    'central_models' => [
        // User REMOVED — context-aware via sync (CentralSyncableUser for central queries).
        // KnativeRegion REMOVED — context-aware via sync (CentralSyncableRegion for central queries).
        // Setting REMOVED — context-aware with fallback (Setting::setFallbackConnection('central')).
        // AuthProvider REMOVED — tenant-scoped via search_path (each tenant has own auth_providers table).
        ExecutorImage::class,
        // Runtime plugins are platform-wide; custom ones carry their tenant_id
        Runtime::class,
        RuntimeBuild::class,
    ],
];
