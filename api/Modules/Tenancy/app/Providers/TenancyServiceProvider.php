<?php

namespace Modules\Tenancy\Providers;

use App\Events\RegionDomainsConfigured;
use App\Events\UserRegistered;
use App\Http\Middleware\ETagResponse;
use App\Http\Middleware\NormalizeApiTags;
use App\Http\Middleware\ResponseCache;
use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Support\Auth\AccountPruner;
use App\Support\Auth\UserDeletionHook;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Console\CreateTenantCommand;
use Modules\Tenancy\Console\MigrateTenantSchemasCommand;
use Modules\Tenancy\Console\TenancyE2eCommand;
use Modules\Tenancy\Console\TenancySetupCommand;
use Modules\Tenancy\Database\Seeders\TenancyDatabaseSeeder;
use Modules\Tenancy\Http\Middleware\CheckTenantQuota;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Http\Middleware\RequireAdmin;
use Modules\Tenancy\Listeners\CreateTenantOnRegistration;
use Modules\Tenancy\Listeners\SetupTenantDomainsOnMigrate;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\CentralSyncableUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Modules\Tenancy\Models\TenantAwareUser;
use Modules\Tenancy\Models\TenantLocalRegion;
use Modules\Tenancy\Providers\Concerns\TenancyServiceOverridesTrait;
use Modules\Tenancy\Services\PostgreSQLSchemaManagerWithPublic;
use Modules\Tenancy\Services\TenantDomainService;
use Modules\Tenancy\Services\TenantRegionReconcileSource;
use Modules\Tenancy\Services\TenantSchema;
use Modules\Tenancy\Support\TenantMembers;
use Modules\Tenancy\Support\TenantRegionShares;
use Modules\Tenancy\Support\TenantTokens;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;

class TenancyServiceProvider extends ServiceProvider
{
    use TenancyServiceOverridesTrait;

    protected string $name = 'Tenancy';

    public function register(): void
    {
        $this->mergeConfigFrom(
            module_path($this->name, 'Config/config.php'),
            'tenancy'
        );

        // White-label: self-service onboarding, tenant branding (docs/white-label.md)
        $this->app->register(TenantWhiteLabelServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->name, 'Database/Migrations'));
        $this->loadViewsFrom(module_path($this->name, 'resources/views'), 'tenancy');

        if (! config('tenancy.enabled')) {
            return;
        }

        $this->overrideStanclDefaults();
        $this->registerTenancyEvents();
        $this->registerSyncEvents();
        $this->setCentralConnections();
        $this->configureSettingFallback();
        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerCommands();
        $this->configureTenantModel();
        $this->registerCentralDatabaseConnection();
        $this->registerServiceOverrides();
        $this->registerSeederOverride();
        $this->registerScheduledJobs();
        $this->registerApiTags();
        $this->registerEventListeners();
        self::registerAccountCleanup();
        // region:reconcile also reconciles each tenant's own regions
        TenantRegionReconcileSource::register();
    }

    /**
     * Override stancl/tenancy defaults that mergeConfigFrom cannot overwrite.
     */
    protected function overrideStanclDefaults(): void
    {
        config([
            'tenancy.database.managers.pgsql' => PostgreSQLSchemaManagerWithPublic::class,
            'tenancy.database.central_connection' => 'central',
            'tenancy.database.prefix' => 'tenant_',
            'tenancy.database.suffix' => '',
            // Remove FilesystemTenancyBootstrapper — git paths are handled by TenantAwareGitService
            'tenancy.bootstrappers' => [
                DatabaseTenancyBootstrapper::class,
                CacheTenancyBootstrapper::class,
                QueueTenancyBootstrapper::class,
            ],
            'tenancy.filesystem.suffix_storage_path' => false,
            // Override migration path — mergeConfigFrom can't override stancl's defaults
            'tenancy.migration_parameters' => TenantSchema::migrationParameters(),
        ]);

        // Other modules register their tenant migrations while they boot (TenantMigrationRegistry):
        // once every provider has booted the parameters name all of them (TenantSchema)
        $this->app->booted(fn () => config(['tenancy.migration_parameters' => TenantSchema::migrationParameters()]));
    }

    /** Register Stancl event listeners (without these, tenancy()->initialize() won't switch DB). */
    protected function registerTenancyEvents(): void
    {
        Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
        Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);
    }

    /** Wire stancl's resource sync and bridge save events for regions/users. */
    protected function registerSyncEvents(): void
    {
        Event::listen(
            SyncedResourceSaved::class,
            UpdateSyncedResource::class,
        );

        $this->registerRegionSyncEvents();
        $this->registerUserSyncEvents();
    }

    protected function registerRegionSyncEvents(): void
    {
        // Kubeconfig resolver: synced regions fetch kubeconfig from central.
        KnativeRegion::setKubeconfigResolver(function ($region) {
            $isSynced = $region->getAttributes()['is_synced'] ?? false;
            if ($isSynced) {
                return KnativeRegion::on('central')
                    ->where('id', $region->getKey())
                    ->value('kubeconfig');
            }

            return null;
        });

        // When the main app saves a platform region in central context,
        // propagate changes to all tenant schemas via CentralSyncableRegion.
        $syncToCopies = function (KnativeRegion $region): void {
            if ($region->getAttribute('owner_tenant_id') !== null) {
                return;
            }
            if (tenancy()->tenant) {
                return;
            }

            $syncable = CentralSyncableRegion::find($region->getKey());
            $syncable?->triggerSyncEvent();
        };
        // The region API saves the route-bound TenantAwareKnativeRegion; status jobs and commands
        // (CheckKnativeStatusJob, DiscoverExternalIpsCommand, …) save App\Models\KnativeRegion
        // instances. Eloquent names a model event after the saved instance's own class, so each
        // save reaches exactly one of these hooks.
        TenantAwareKnativeRegion::saved($syncToCopies);
        KnativeRegion::saved($syncToCopies);

        // A region created in a tenant's context is the tenant's own (owner_tenant_id, not
        // synced): the region API creates App\Models\KnativeRegion rows, which the
        // TenantAwareKnativeRegion hook never sees
        KnativeRegion::creating(fn (KnativeRegion $region) => TenantAwareKnativeRegion::markTenantOwned($region));

        // A platform region deleted centrally takes every tenant's copy with it (projects on it
        // stay without a region, like central ones). The assignments are read before the delete
        // cascades over tenant_regions.
        $removeCopies = function (KnativeRegion $region): void {
            if (tenancy()->initialized || $region->getAttribute('owner_tenant_id')) {
                return;
            }

            TenantLocalRegion::removeCopiesOf((string) $region->getKey());
        };
        KnativeRegion::deleting($removeCopies);
        TenantAwareKnativeRegion::deleting($removeCopies);
        CentralSyncableRegion::deleting($removeCopies);
    }

    protected function registerUserSyncEvents(): void
    {
        // Bridge: when TenantAwareUser saved in central context → sync to tenants
        TenantAwareUser::saved(function ($user) {
            if (tenancy()->tenant) {
                return; // in tenant context, don't sync back
            }

            $syncable = CentralSyncableUser::find($user->getKey());
            $syncable?->triggerSyncEvent();
        });
    }

    protected function setCentralConnections(): void
    {
        $models = config('tenancy.central_models', []);

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            /** @var Model $modelClass */
            $modelClass::creating(fn ($m) => $m->setConnection('central'));
            /** @var Model $modelClass */
            $modelClass::updating(fn ($m) => $m->setConnection('central'));
            /** @var Model $modelClass */
            $modelClass::retrieved(fn ($m) => $m->setConnection('central'));
        }
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('tenancy.identify', IdentifyTenant::class);
        $router->aliasMiddleware('tenancy.quota', CheckTenantQuota::class);
        $router->aliasMiddleware('tenancy.admin', RequireAdmin::class);

        // IdentifyTenant must run AFTER auth (needs $request->user())
        // but BEFORE ResponseCache (stancl's cache bootstrapper must be active).
        $priority = $router->middlewarePriority;
        $authIdx = array_search(AuthenticatesRequests::class, $priority);
        $insertAt = $authIdx !== false ? $authIdx + 1 : count($priority);
        array_splice($priority, $insertAt, 0, [IdentifyTenant::class]);
        $priority[] = ETagResponse::class;
        $priority[] = ResponseCache::class;
        $router->middlewarePriority = $priority;

        $router->pushMiddlewareToGroup('api', IdentifyTenant::class);
        $router->pushMiddlewareToGroup('api', CheckTenantQuota::class);
    }

    protected function registerRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(module_path($this->name, 'routes/api.php'));

        Route::middleware('api')
            ->prefix('api')
            ->group(module_path($this->name, 'routes/public.php'));
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TenancySetupCommand::class,
                CreateTenantCommand::class,
                MigrateTenantSchemasCommand::class,
                TenancyE2eCommand::class,
            ]);
        }
    }

    protected function configureTenantModel(): void
    {
        config([
            'tenancy.tenant_model' => Tenant::class,
        ]);
    }

    /** Ensure the 'central' database connection exists (mirrors default with search_path=public). */
    protected function registerCentralDatabaseConnection(): void
    {
        if (config('database.connections.central')) {
            return;
        }

        $default = config('database.default');
        $config = config("database.connections.{$default}");

        if ($default === 'pgsql') {
            $config['search_path'] = 'public';
        }

        config(['database.connections.central' => $config]);
    }

    /** Setting::get() fallback: tenant schema first, central if not found. */
    protected function configureSettingFallback(): void
    {
        Setting::setFallbackConnection('central');
    }

    protected function registerSeederOverride(): void
    {
        $this->app->bind(
            DatabaseSeeder::class,
            TenancyDatabaseSeeder::class,
        );
    }

    protected function registerApiTags(): void
    {
        NormalizeApiTags::registerTags([
            'Tenant' => 'Tenants',
            'TenantUser' => 'Tenants > Users',
            'TenancyUser' => 'Tenants > Users',
            'Invitation' => 'Tenants > Invitations',
            'Impersonate' => 'Tenants > Impersonate',
            'ResourceUsage' => 'Tenants > Resources',
        ]);
    }

    protected function registerEventListeners(): void
    {
        Event::listen(
            RegionDomainsConfigured::class,
            SetupTenantDomainsOnMigrate::class,
        );

        Event::listen(
            UserRegistered::class,
            CreateTenantOnRegistration::class,
        );
    }

    /**
     * A deleted account's copies in its organizations go with it (UserTokens::deleteUser);
     * tokens:prune removes the tokens organizations refuse for good and copies of deleted users.
     */
    public static function registerAccountCleanup(): void
    {
        app()->tag([TenantMembers::class], UserDeletionHook::HOOKS);
        app()->tag([TenantTokens::class, TenantMembers::class], AccountPruner::PRUNERS);
    }

    protected function registerScheduledJobs(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Metering (usage-aggregation) is scheduled by the core for every organization
            // context (routes/console.php); billing is the Billing module (docs/billing.md).

            // A moved organization domain takes over once DNS of the new domain answers the
            // region's external address (TenantDomainService)
            $schedule->call(fn () => $this->app->make(TenantDomainService::class)->completeResolvedMigrations())
                ->everyFiveMinutes()
                ->name('tenant-domain-migration-auto-complete')
                ->withoutOverlapping(30);

            // Region offers wait for acceptance only while billing is on: with billing off they are
            // active (TenantRegionShares::activatePending)
            $schedule->call(fn () => TenantRegionShares::activatePending())
                ->everyFiveMinutes()
                ->name('tenant-region-shares-activate')
                ->withoutOverlapping(10);
        });
    }
}
