<?php

namespace App\Support\Rbac;

use App\Support\Rbac\Contracts\Organization;

/**
 * Which organization the current request runs in:
 *  - an initialized tenant (Tenancy module)         → Modules\Tenancy\Support\TenantOrganization
 *  - multi-tenant central context (no active tenant) → none: platform admins only
 *  - single-tenant install                           → the install itself
 */
class OrganizationResolver
{
    private const TENANT_ORGANIZATION = 'Modules\\Tenancy\\Support\\TenantOrganization';

    public function current(): Organization
    {
        if (function_exists('tenancy') && tenancy()->initialized && tenancy()->tenant && class_exists(self::TENANT_ORGANIZATION)) {
            $class = self::TENANT_ORGANIZATION;

            return new $class(tenancy()->tenant);
        }

        if (config('tenancy.enabled')) {
            return new NoOrganization;
        }

        return new SingleTenantOrganization;
    }
}
