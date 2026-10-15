<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name' => 'sometimes|string|max:255',
            'username' => ['sometimes', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'bio' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:32',
        ];
    }
}
