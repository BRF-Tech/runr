<?php

namespace App\Http\Requests\KnativeRegion;

use App\Services\Helpers\RegionCapacity;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /knative-regions/{id}/capacity: the region's FaaS overcommit factors (regions.manage). */
class UpdateRegionCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $range = 'numeric|min:'.RegionCapacity::MIN_OVERCOMMIT.'|max:'.RegionCapacity::MAX_OVERCOMMIT.'|decimal:0,2';

        return [
            'faas_cpu_overcommit' => 'required_without:faas_memory_overcommit|'.$range,
            'faas_memory_overcommit' => 'required_without:faas_cpu_overcommit|'.$range,
        ];
    }
}
