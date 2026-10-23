<?php

namespace App\Providers;

use App\Models\Deployment;
use App\Models\Setting;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Runtime plugins: the registry (one instance per request / job — Octane-safe) and the custom
 * runtime switch on top of the runtimes.use_custom permission; runtime patches (the patch set of
 * a request, the migration check when a deployment changes).
 */
class RuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManifestValidator::class);
        $this->app->singleton(BuiltinCatalog::class);
        $this->app->scoped(RuntimeRegistry::class);
        // Runtime patches: the feed's patches per runtime version, read once per request / job
        $this->app->scoped(RuntimePatches::class);
    }

    public function boot(): void
    {
        // After every provider booted: refines the catalog's definition of runtimes.use_custom
        $this->app->booted(fn () => $this->defineRuntimeGates());

        // A deployment of a runtime patch migration became active or failed: checked right away
        // (rolled back when RunR started it), not only by the five-minute job
        Deployment::updated(function (Deployment $deployment) {
            if (! $deployment->wasChanged('status') || ! in_array($deployment->status, ['active', 'failed', 'shut_down'], true)) {
                return;
            }
            $check = function () use ($deployment) {
                try {
                    app(RuntimePatchMigrations::class)->deploymentChanged($deployment);
                } catch (\Throwable $e) {
                    Log::warning('Runtime patch migration not checked after a deployment change', ['deployment' => $deployment->id, 'error' => $e->getMessage()]);
                }
            };

            // After the activation's transaction (LiveDeployments::activate()) committed
            try {
                $deployment->getConnection()->afterCommit($check);
            } catch (\RuntimeException) {
                $check(); // a connection without a transactions manager
            }
        });
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
