<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class UpgradeServingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // the next Knative minor version ("1.21"), from GET …/compatible-versions
            'version' => ['required', 'string', 'max:20', 'regex:/^v?\d+\.\d+(\.\d+)?$/'],
        ];
    }
}
