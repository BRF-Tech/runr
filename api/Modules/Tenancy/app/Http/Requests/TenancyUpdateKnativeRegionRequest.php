<?php

namespace Modules\Tenancy\Http\Requests;

use App\Http\Requests\KnativeRegion\UpdateKnativeRegionRequest;
use App\Services\Helpers\RegionModeAccess;

class TenancyUpdateKnativeRegionRequest extends UpdateKnativeRegionRequest
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
