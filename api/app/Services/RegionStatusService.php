<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Concerns\RegionStatusTraefikTrait;

/**
 * Region status (active / inactive / maintenance) and its status block on the region Traefik
 * (RegionStatusTraefikTrait): applied when the status changes, when Knative Serving of a region
 * that is not active becomes ready (install or adoption), when the hosts it covers change
 * (base-domain migration) and by region:reconcile.
 */
class RegionStatusService
{
    use RegionStatusTraefikTrait;

    /**
     * Change region status and sync Traefik blocking resources.
     */
    public function changeStatus(KnativeRegion $region, string $newStatus): void
    {
        $region->update(['region_status' => $newStatus]);

        $this->syncBlock($region);
    }

    /**
     * The status block of the region as its status wants it: applied (or brought up to date) for
     * an inactive or maintenance region, removed for an active one. Only where Knative Serving is
     * ready — the block lives in its namespace.
     *
     * @return list<string> what changed (or, with $dryRun, would change)
     */
    public function syncBlock(KnativeRegion $region, bool $dryRun = false): array
    {
        if (! $region->has_serving || ! $region->kubeconfig || $region->is_demo) {
            return [];
        }

        return $this->syncRegionBlockRoute($region, $dryRun);
    }
}
