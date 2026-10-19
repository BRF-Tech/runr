<?php

namespace Modules\Tenancy\Http\Requests;

use App\Http\Requests\KnativeRegion\StoreKnativeRegionRequest;
use App\Services\Helpers\RegionModeAccess;

class TenancyStoreKnativeRegionRequest extends StoreKnativeRegionRequest
{
    /**
     * region_mode (managed / infinite tenant quotas): platform administrators only — anyone
     * else's value is not taken (RegionModeAccess).
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), RegionModeAccess::rules($this));
    }
}
