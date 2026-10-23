<?php

namespace App\Providers;

use App\Services\Audit\AuditChanges;
use App\Services\Audit\AuditLogger;
use App\Services\Secrets\OpenBaoClients;
use App\Services\Secrets\RegionSecrets;
use App\Services\Secrets\SecretBackends;
use Illuminate\Support\ServiceProvider;

/**
 * Secrets at rest and the audit log (docs/security.md): audit services and the model change
 * collector. The abilities they are gated by (audit.view, audit.export, secrets.view_values)
 * are RBAC permissions (RbacServiceProvider, config/permissions.php).
 *
 * Project secrets: the backends by source (SecretBackends — the values live in the region or in
 * an organization's secret store, never in RunR's database) and the region Secret client.
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request (Octane): the change collector holds the current request's changes
        $this->app->scoped(AuditChanges::class);
        $this->app->scoped(AuditLogger::class);

        // Stateless: a backend registered by a module stays for the worker's life
        $this->app->singleton(SecretBackends::class);
        $this->app->singleton(RegionSecrets::class);
        $this->app->singleton(OpenBaoClients::class);
    }

    public function boot(): void
    {
        AuditChanges::listen();
    }
}
