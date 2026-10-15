<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Concerns\RegionStatusTraefikTrait;

class RegionStatusService
{
    use RegionStatusTraefikTrait;

    /**
     * Change region status and sync Traefik blocking resources.
     */
    public function changeStatus(KnativeRegion $region, string $newStatus): void
    {
        $region->update(['region_status' => $newStatus]);

        if ($region->has_serving && $region->kubeconfig && ! $region->is_demo) {
            $this->syncRegionBlockRoute($region);
        }
    }
}
