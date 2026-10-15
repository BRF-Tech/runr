<?php

namespace App\Http\Requests\KnativeRegion;

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
            'kubeconfig' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
            'region_status' => 'sometimes|string|in:active,inactive,maintenance',
        ];
    }
}
