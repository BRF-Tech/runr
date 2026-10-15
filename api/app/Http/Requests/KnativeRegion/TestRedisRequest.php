<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class TestRedisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address' => 'required|string|max:255',
            'password' => 'nullable|string|max:255',
        ];
    }
}
