<?php

namespace Modules\Tenancy\Http\Middleware;

use App\Http\Middleware\EnsureRegionMutable;
use App\Models\KnativeRegion;
use App\Support\Rbac\Rbac;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `region.mutable` with tenancy enabled (bound over App\Http\Middleware\EnsureRegionMutable).
 *
 * In tenant context route model binding resolves the tenant's copy of a region. A synced copy of
 * a platform region (is_synced = true) resolves its kubeconfig from central, so without this
 * guard any tenant user could reconfigure — or download the credentials of — a region every
 * tenant runs on (e.g. PUT function-tls replaces the certificate served for all tenants).
 *
 *  - platform admins (users.is_admin): always allowed, in or out of tenant context
 *  - tenant context, platform region (synced copy): 403
 *  - tenant context, tenant-owned region (or creating one): members with regions.manage,
 *    sandbox.manage or registry.manage (owners and admins by default)
 *  - no tenant context: platform admins only
 */
class EnsureTenantRegionMutable extends EnsureRegionMutable
{
    /** Tenant members holding one of these may change the tenant's own regions (owners, admins) */
    private const REGION_PERMISSIONS = ['regions.manage', 'sandbox.manage', 'registry.manage'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        $tenant = tenancy()->tenant ?? $request->attributes->get('tenant');

        if (! $tenant) {
            abort(403, __('api.region.platform_admin_required'));
        }

        $region = $this->routeRegion($request);

        if ($region && ! $this->isTenantOwned($region, (string) $tenant->getTenantKey())) {
            abort(403, __('api.region.platform_region_read_only'));
        }

        // The route's exact permission is checked by AuthorizeApiRoute (config/route_permissions.php)
        $rbac = app(Rbac::class);
        $mayManage = collect(self::REGION_PERMISSIONS)->contains(fn (string $p) => $rbac->allows($user, $p));

        if (! $mayManage) {
            abort(403, __('api.region.region_admin_required'));
        }

        return $next($request);
    }

    /**
     * A synced copy (is_synced) is a platform region; a tenant-owned region lives only in the
     * tenant's own database (is_synced = false). owner_tenant_id is checked too where it is set.
     */
    private function isTenantOwned(KnativeRegion $region, string $tenantId): bool
    {
        $attributes = $region->getAttributes();

        if ((bool) ($attributes['is_synced'] ?? false)) {
            return false;
        }

        $owner = $attributes['owner_tenant_id'] ?? null;

        return $owner === null || (string) $owner === $tenantId;
    }

    /**
     * The region route parameter ({knativeRegion}, or {knative_region} on the apiResource
     * routes) — already a model after SubstituteBindings; resolved the same way
     * (container-bound model, current connection) if it is still an id.
     */
    private function routeRegion(Request $request): ?KnativeRegion
    {
        $value = $request->route('knativeRegion') ?? $request->route('knative_region');

        if ($value instanceof KnativeRegion || $value === null) {
            return $value;
        }

        $region = app(KnativeRegion::class)->resolveRouteBinding($value);

        return $region instanceof KnativeRegion ? $region : null;
    }
}
