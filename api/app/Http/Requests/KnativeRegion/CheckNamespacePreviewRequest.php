<?php

namespace App\Http\Requests\KnativeRegion;

use App\Rules\RegionKubeconfig;
use Illuminate\Foundation\Http\FormRequest;

class CheckNamespacePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kubeconfig' => ['required', 'string', RegionKubeconfig::forNewRegion()],
            'name' => 'required|string|max:63',
        ];
    }
}
