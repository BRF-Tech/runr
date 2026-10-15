<?php

namespace Modules\Tenancy\Http\Requests;

use App\Http\Requests\KnativeRegion\UpdateKnativeRegionRequest;

class TenancyUpdateKnativeRegionRequest extends UpdateKnativeRegionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'region_mode' => 'sometimes|in:managed,infinite',
        ]);
    }
}
