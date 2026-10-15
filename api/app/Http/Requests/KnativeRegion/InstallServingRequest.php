<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class InstallServingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'namespace' => 'sometimes|string|max:63',
            'version' => 'sometimes|nullable|string|max:20',
        ];
    }
}
