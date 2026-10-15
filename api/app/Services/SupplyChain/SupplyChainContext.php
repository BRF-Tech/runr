<?php

namespace App\Services\SupplyChain;

use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;

/**
 * Database context of scheduled supply-chain work: the platform (central) context, and with the
 * Tenancy module every tenant (their projects, versions and reports live in their own schema;
 * tenant-owned regions exist only there).
 */
final class SupplyChainContext
{
    /** Runs $callback once in the current (central) context, then in each tenant. */
    public static function each(callable $callback): void
    {
        $callback(null);

        foreach (self::tenants() as $tenant) {
            try {
                $tenant->run(fn () => $callback($tenant));
            } catch (\Throwable $e) {
                Log::warning('Supply-chain task failed in tenant', ['tenant' => $tenant->getTenantKey(), 'error' => $e->getMessage()]);
            }
        }
    }

    /** Runs $callback with the region of a settings row, in the context the region lives in. */
    public static function forRegion(RegionSupplyChainSetting $settings, callable $callback): void
    {
        $tenantClass = '\Modules\Tenancy\Models\Tenant';

        if ($settings->owner_tenant_id && config('tenancy.enabled') && class_exists($tenantClass)) {
            $tenant = $tenantClass::find($settings->owner_tenant_id);
            $tenant?->run(function () use ($settings, $callback) {
                $region = KnativeRegion::find($settings->knative_region_id);
                if ($region) {
                    $callback($region);
                }
            });

            return;
        }

        $region = KnativeRegion::find($settings->knative_region_id);
        if ($region) {
            $callback($region);
        }
    }

    /** @return iterable<Tenant> */
    private static function tenants(): iterable
    {
        $tenantClass = '\Modules\Tenancy\Models\Tenant';
        if (! config('tenancy.enabled') || ! class_exists($tenantClass)) {
            return [];
        }

        return $tenantClass::query()->get()->filter(fn ($t) => $t->isActive());
    }
}
