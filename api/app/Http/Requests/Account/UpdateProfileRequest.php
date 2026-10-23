<?php

namespace App\Http\Requests\Account;

use App\Rules\UniqueIgnoringCase;
use Illuminate\Foundation\Http\FormRequest;

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
            // alpha_dash: never "@"; unique in any letter case, as the sign-in matches (LoginIdentifier)
            'username' => ['sometimes', 'string', 'max:64', 'alpha_dash', new UniqueIgnoringCase('users', 'username', $userId)],
            'email' => ['sometimes', 'email', new UniqueIgnoringCase('users', 'email', $userId)],
            'bio' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:32',
        ];
    }
}
