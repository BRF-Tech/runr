<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `region.mutable`: guards every route that changes a region or hands out
 * its credentials (create/update/delete, settings, install/uninstall, sandbox, registry, Redis,
 * demo projects, function-domain TLS, workers/node taints, migration, kubeconfig download).
 *
 * A single-tenant install has one kind of user — every signed-in user manages regions — so this
 * base implementation lets the request through. The Tenancy module binds this class to
 * Modules\Tenancy\Http\Middleware\EnsureTenantRegionMutable, which makes platform regions
 * read-only for tenant users and limits tenant-owned regions to the tenant's owner/admin.
 */
class EnsureRegionMutable
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
