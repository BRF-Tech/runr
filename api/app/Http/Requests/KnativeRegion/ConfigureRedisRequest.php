<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureRedisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|string|in:in_cluster,external,managed',
            'address' => 'nullable|required_unless:mode,managed|string|max:255',
            'password' => 'nullable|string|max:255',
        ];
    }
}
