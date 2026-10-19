<?php

namespace Tests\Feature\WhiteLabel\Concerns;

use App\Http\Middleware\AuthorizeApiRoute;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\WhiteLabel\PlatformSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Tests\Concerns\WiresTenancy;
use Tests\Support\ActingTenant;

/**
 * Multi-tenant white-label parts on the test database (the suite runs with TENANCY_ENABLED off):
 * the central connection + Tenancy module migrations (WiresTenancy), tenancy switched on in
 * config, the white-label route file of the Tenancy module (onboarding, tenant branding). Tenant
 * context comes from the X-Test-Tenant header (ActingTenant) instead of IdentifyTenant — such
 * tenants need a schema (createTenantWithSchema with WL_TENANT_MIGRATIONS): the tenant is the RBAC
 * organization.
 */
trait WhiteLabelTenancy
{
    use WiresTenancy;

    /** Tenant schema of the white-label tests: region tables + projects / usage / MaaS configs */
    protected const WL_TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0009_create_usage_records_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0021_create_ai_config_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0022_add_agent_token_hash_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0024_add_function_tls_to_knative_regions.php',
        // Roles & permissions: custom roles, groups, project access of the tenant
        'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
    ];

    protected function setUpWhiteLabelTenancy(bool $routes = true): void
    {
        // Central connection + every Tenancy module migration (tenants, tenant_regions,
        // tenant_onboarding, …)
        $this->setUpTenancy();

        config([
            'tenancy.enabled' => true,
            'quota.cpu' => null,
            'quota.memory_gib' => null,
            'app.url' => 'https://console.example.com',
            'app.frontend_url' => 'https://console.example.com',
        ]);

        // secure_settings are encrypted with the app key
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        // route() / signed URLs point at the console host
        URL::forceRootUrl('https://console.example.com');
        URL::forceScheme('https');

        app(PlatformSettings::class)->flush();

        if ($routes) {
            // The test tenant context is set up before the route's permission check, like IdentifyTenant
            $router = $this->app['router'];
            $priority = $router->middlewarePriority;
            $at = array_search(AuthorizeApiRoute::class, $priority, true);
            array_splice($priority, $at === false ? count($priority) : $at, 0, [ActingTenant::class]);
            $router->middlewarePriority = $priority;

            Route::middleware(['api', 'auth:sanctum', ActingTenant::class])
                ->prefix('api')
                ->group(base_path('Modules/Tenancy/routes/white-label.php'));
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->refreshActionLookups();
        }
    }

    protected function makeUser(array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::factory()->create(array_merge(['username' => 'wl-user-'.$n.'-'.uniqid()], $attrs));
    }

    protected function admin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    protected function member(Tenant $tenant, string $role = 'owner'): User
    {
        $user = $this->makeUser();
        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function plainTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    protected function platformRegion(string $name = 'eu-1', array $attrs = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => $name,
            'project_namespace' => $name,
            'is_active' => true,
            'total_cpu_m' => 16000,
            'total_memory_mb' => 32768,
        ], $attrs));
    }

    protected function attachRegion(Tenant $tenant, KnativeRegion $region, int $cpu = 1000, int $mem = 2048): void
    {
        $tenant->regions()->attach($region->id, ['cpu_quota_m' => $cpu, 'memory_quota_mb' => $mem]);
    }

    protected function tenantHeaders(Tenant $tenant): array
    {
        return ['X-Test-Tenant' => (string) $tenant->id, 'Accept' => 'application/json'];
    }
}
