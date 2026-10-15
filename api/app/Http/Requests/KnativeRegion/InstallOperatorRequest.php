<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class InstallOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'namespace' => 'sometimes|string|max:63',
        ];
    }
}
