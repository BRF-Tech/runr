<?php

namespace Modules\Tenancy\Http\Controllers\WhiteLabel\Concerns;

use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesTenantContext
{
    /**
     * The tenant the request runs in (IdentifyTenant: the member's tenant, or X-Tenant-ID for a
     * platform admin).
     */
    protected function tenantOf(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            throw new HttpException(422, __('whitelabel.tenant_required'));
        }

        return $tenant;
    }
}
