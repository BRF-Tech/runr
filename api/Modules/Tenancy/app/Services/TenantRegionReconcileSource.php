<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Adds each tenant's own regions (is_synced = false, own kubeconfig) to `region:reconcile`: the
 * active ones to the cluster steps (reconcilable()), and every one with a ready Knative Serving —
 * inactive and in maintenance included — to the status-block pass (blockable()), run in the
 * tenant's context like the central pass is for central regions.
 * Tenant copies of platform regions (is_synced = true) are not listed: the central row is
 * reconciled and its changes reach the copies through the region sync.
 */
class TenantRegionReconcileSource
{
    public static function register(): void
    {
        RegionReconciler::addRegionSource('tenancy', fn (?string $regionId) => self::targets($regionId));
        RegionReconciler::addStatusBlockSource('tenancy', fn (?string $regionId) => self::blockTargets($regionId));
    }

    /**
     * The tenants' own regions the cluster steps reconcile.
     *
     * @return list<ReconcileTarget>
     */
    public static function targets(?string $regionId): array
    {
        return self::collect($regionId, fn (Builder $query) => RegionReconciler::reconcilable($query));
    }

    /**
     * The tenants' own regions whose status block the reconcile keeps as their status wants it.
     *
     * @return list<ReconcileTarget>
     */
    public static function blockTargets(?string $regionId): array
    {
        return self::collect($regionId, fn (Builder $query) => RegionReconciler::blockable($query));
    }

    /**
     * @param  Closure(Builder): Builder  $filter
     * @return list<ReconcileTarget>
     */
    private static function collect(?string $regionId, Closure $filter): array
    {
        $targets = [];

        foreach (Tenant::all() as $tenant) {
            try {
                $regions = $tenant->run(function () use ($regionId, $filter) {
                    $query = $filter(KnativeRegion::query())->where('is_synced', false);
                    if ($regionId) {
                        $query->where('id', $regionId);
                    }

                    return $query->get();
                });
            } catch (Throwable $e) {
                Log::warning('region:reconcile could not read tenant regions', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($regions as $region) {
                $targets[] = new ReconcileTarget(
                    $region,
                    "tenant {$tenant->slug}",
                    fn (callable $fn) => $tenant->run($fn),
                );
            }
        }

        return $targets;
    }
}
