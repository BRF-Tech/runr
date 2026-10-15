<?php

namespace App\Providers;

use App\Services\Audit\AuditChanges;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\ServiceProvider;

/**
 * Secrets at rest and the audit log (docs/security.md): audit services and the model change
 * collector. The abilities they are gated by (audit.view, audit.export, secrets.view_values)
 * are RBAC permissions (RbacServiceProvider, config/permissions.php).
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request (Octane): the change collector holds the current request's changes
        $this->app->scoped(AuditChanges::class);
        $this->app->scoped(AuditLogger::class);
    }

    public function boot(): void
    {
        AuditChanges::listen();
    }
}
