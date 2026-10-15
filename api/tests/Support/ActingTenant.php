<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test stand-in for IdentifyTenant: the tenant named by the X-Test-Tenant header becomes the
 * request's tenant — tenancy is initialized (the tenant is the RBAC organization of the request,
 * its schema the default connection) and ended again after the response. The tenant needs a
 * schema (WiresTenancy::createTenantWithSchema). Registered in the middleware priority before
 * App\Http\Middleware\AuthorizeApiRoute, like IdentifyTenant.
 */
class ActingTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Test-Tenant');
        $tenant = $id ? Tenant::query()->find($id) : null;

        if (! $tenant) {
            return $next($request);
        }

        tenancy()->initialize($tenant);
        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            tenancy()->end();
        }
    }
}
