<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Services\Runtimes\RuntimeRegistry;
use Modules\Tenancy\Models\Tenant;

/**
 * Organization context of the Sandbox API. Sandbox rows are central (tenant_id); regions and their
 * kubeconfigs are context-aware — a tenant-owned region exists only in the tenant's schema — so a
 * job working on a sandbox of a tenant reads its region inside that tenant.
 */
final class SandboxContext
{
    /** Tenant of the current request / job (null: single-tenant install or central context). */
    public static function tenantId(): ?string
    {
        return RuntimeRegistry::currentTenantId();
    }

    public static function tenancyEnabled(): bool
    {
        return (bool) config('tenancy.enabled') && class_exists(Tenant::class);
    }

    /**
     * Run $callback in the tenant's context ($tenantId null, tenancy off or already in that
     * tenant: directly).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(?string $tenantId, callable $callback): mixed
    {
        if ($tenantId === null || ! self::tenancyEnabled() || self::tenantId() === $tenantId) {
            return $callback();
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            throw new SandboxException(__('sandboxes.tenant_gone'), 404, 'tenant_gone');
        }

        return $tenant->run($callback);
    }

    /** A region as the organization sees it (its copy of a platform region, or its own region). */
    public static function region(string $regionId, ?string $tenantId): ?KnativeRegion
    {
        return self::run($tenantId, fn () => KnativeRegion::find($regionId));
    }

    /** Tenant slug for the namespace name (null without tenancy / for the central context). */
    public static function tenantSlug(?string $tenantId): ?string
    {
        if ($tenantId === null || ! self::tenancyEnabled()) {
            return null;
        }

        return Tenant::find($tenantId)?->slug ?? $tenantId;
    }
}
