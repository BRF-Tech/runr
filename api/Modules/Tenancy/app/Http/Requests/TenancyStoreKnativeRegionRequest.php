<?php

namespace Modules\Tenancy\Http\Requests;

use App\Http\Requests\KnativeRegion\StoreKnativeRegionRequest;

class TenancyStoreKnativeRegionRequest extends StoreKnativeRegionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'region_mode' => 'sometimes|in:managed,infinite',
        ]);
    }
}
