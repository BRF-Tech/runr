<?php

namespace App\Http\Requests\KnativeRegion;

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
            'kubeconfig' => 'required|string',
            'name' => 'required|string|max:63',
        ];
    }
}
