<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Adds each tenant's own regions (is_synced = false, own kubeconfig) to `region:reconcile`.
 * Tenant copies of platform regions (is_synced = true) are not listed: the central row is
 * reconciled and its changes reach the copies through the region sync.
 */
class TenantRegionReconcileSource
{
    public static function register(): void
    {
        RegionReconciler::addRegionSource('tenancy', fn (?string $regionId) => self::targets($regionId));
    }

    /**
     * @return list<ReconcileTarget>
     */
    public static function targets(?string $regionId): array
    {
        $targets = [];

        foreach (Tenant::all() as $tenant) {
            try {
                $regions = $tenant->run(function () use ($regionId) {
                    $query = RegionReconciler::reconcilable(KnativeRegion::query())->where('is_synced', false);
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
