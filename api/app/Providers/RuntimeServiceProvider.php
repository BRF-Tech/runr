<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Runtime plugins: the registry (one instance per request / job — Octane-safe) and the custom
 * runtime switch on top of the runtimes.use_custom permission.
 */
class RuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManifestValidator::class);
        $this->app->singleton(BuiltinCatalog::class);
        $this->app->scoped(RuntimeRegistry::class);
    }

    public function boot(): void
    {
        // After every provider booted: refines the catalog's definition of runtimes.use_custom
        $this->app->booted(fn () => $this->defineRuntimeGates());
    }

    /**
     * Runtime abilities are catalog permissions (config/permissions.php, docs/permissions.md),
     * resolved by the permission model like every other ability:
     *  - runtimes.manage     platform runtimes (single-tenant: Owner / Admin; multi-tenant:
     *                        platform administrators — every tenant shares them)
     *  - runtimes.use_custom the organization's own runtimes and projects on them
     *
     * Custom runtimes are a platform feature: runtimes.use_custom applies while the platform
     * setting custom_runtimes_enabled is on — and always to whoever manages runtimes.
     */
    private function defineRuntimeGates(): void
    {
        Gate::define('runtimes.use_custom', fn (?Authenticatable $user) => $user !== null && (
            Gate::forUser($user)->allows('runtimes.manage')
            || (Setting::get('custom_runtimes_enabled', 'false') === 'true'
                && app(Rbac::class)->allows($user, 'runtimes.use_custom'))
        ));
    }
}
