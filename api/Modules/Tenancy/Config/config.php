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

        // Schema naming: tenant_{slug}
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

    'migration_parameters' => [
        '--force' => true,
        '--path' => [base_path('Modules/Tenancy/Database/TenantMigrations')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],

    /**
     * Billing (docs/billing.md): RunR meters and rates usage and calculates what each tenant owes
     * per month (charges); the operator's billing system reads them through the billing API,
     * invoices, and records purchases back. Without an active cost config (per region or global)
     * usage is still recorded — at zero cost, in default_currency (ISO 4217, 3 letters).
     */
    'billing' => [
        'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'USD'),

        /**
         * Billing events are POSTed to this URL (empty: kept for GET /api/billing/events only),
         * signed with the secret: X-RunR-Signature = "sha256=" + HMAC-SHA256(secret,
         * "<X-RunR-Timestamp>.<raw body>"). Platform admins override both in Settings → Plans & Billing.
         */
        'webhook' => [
            'url' => env('BILLING_WEBHOOK_URL', ''),
            'secret' => env('BILLING_WEBHOOK_SECRET', ''),
            'timeout' => (int) env('BILLING_WEBHOOK_TIMEOUT', 10),
            // Attempts per event (backoff 1 min … 24 h), then delivery_status "failed"
            'max_attempts' => (int) env('BILLING_WEBHOOK_MAX_ATTEMPTS', 10),
        ],

        'events' => [
            'retention_days' => (int) env('BILLING_EVENTS_RETENTION_DAYS', 90),
            // Month-to-date usage amounts that raise billing.usage.threshold, e.g. "100,500,1000"
            'usage_thresholds' => env('BILLING_USAGE_THRESHOLDS', ''),
            // Plan limit invocations_per_month: events at these percentages
            'limit_percentages' => [80, 100],
        ],

        // The operator's store where tenants buy plans and credits (a link in the console)
        'store_url' => env('BILLING_STORE_URL', ''),
    ],

    /**
     * Plans (docs/billing.md) — optional, off by default. Off: no plan appears anywhere and
     * tenant quotas are managed per region by the platform admin as before.
     */
    'plans' => [
        'enabled' => env('PLANS_ENABLED', false),
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
