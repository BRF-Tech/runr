<?php

namespace App\Http\Requests\RegistrySetup;

use Illuminate\Foundation\Http\FormRequest;

class TestExternalRegistryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kubeconfig' => 'required|string',
            'url' => 'required|string',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'namespace' => 'sometimes|string|max:63',
        ];
    }
}
