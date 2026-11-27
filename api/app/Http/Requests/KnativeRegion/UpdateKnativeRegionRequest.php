<?php

namespace App\Http\Requests\KnativeRegion;

use App\Rules\RegionKubeconfig;
use Illuminate\Foundation\Http\FormRequest;

class UpdateKnativeRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kubeconfig' => ['sometimes', 'string', RegionKubeconfig::forRegion($this->route('knative_region'))],
            'is_active' => 'sometimes|boolean',
            'region_status' => 'sometimes|string|in:active,inactive,maintenance',
        ];
    }
}
