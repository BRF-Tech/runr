<?php

namespace App\Http\Requests\RegistrySetup;

use App\Rules\RegionKubeconfig;
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
            'kubeconfig' => ['required', 'string', RegionKubeconfig::forNewRegion()],
            'storageClass' => 'required|string',
            'size' => 'required|string',
            'namespace' => 'sometimes|string|max:63',
        ];
    }
}
