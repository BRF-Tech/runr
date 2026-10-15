<?php

namespace App\Http\Requests\RegistrySetup;

use Illuminate\Foundation\Http\FormRequest;

class TestPvcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kubeconfig' => 'required|string',
            'storageClass' => 'required|string',
            'size' => 'required|string',
            'namespace' => 'sometimes|string|max:63',
        ];
    }
}
