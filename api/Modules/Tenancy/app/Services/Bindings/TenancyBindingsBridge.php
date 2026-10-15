<?php

namespace Modules\Tenancy\Services\Bindings;

use App\Services\Bindings\BindingTenancy;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantBindingQuota;

/**
 * Bindings with tenancy on (App\Providers\BindingServiceProvider calls register()):
 *
 *  - scheduled binding work runs in the central context and in every tenant's;
 *  - per-tenant limits from tenant_binding_quotas (GET/PUT /api/tenants/{tenant}/binding-quotas);
 *  - audit records carry the tenant id.
 */
class TenancyBindingsBridge
{
    public static function register(): void
    {
        BindingTenancy::setRunner(function (callable $callback) {
            $original = tenancy()->tenant;
            if ($original) {
                tenancy()->end();
            }

            try {
                $callback();
                foreach (Tenant::query()->cursor() as $tenant) {
                    $tenant->run(fn () => $callback());
                }
            } finally {
                if ($original) {
                    tenancy()->initialize($original);
                } elseif (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        });

        BindingTenancy::setQuotaResolver(function () {
            $tenant = tenancy()->tenant;

            return $tenant ? TenantBindingQuota::query()->find($tenant->getTenantKey())?->limits() : null;
        });

        BindingTenancy::setTenantKeyResolver(fn () => tenancy()->tenant?->getTenantKey() !== null ? (string) tenancy()->tenant->getTenantKey() : null);
    }
}
