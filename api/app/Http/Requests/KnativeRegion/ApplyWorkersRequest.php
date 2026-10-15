<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class ApplyWorkersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nodes' => 'required|array',
            'nodes.*' => 'string',
        ];
    }
}
