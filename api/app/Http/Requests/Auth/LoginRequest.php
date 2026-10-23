<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\LoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The user name or the e-mail address of the account, in any letter case. */
            'login' => ['required', 'string', 'max:'.LoginIdentifier::MAX_LENGTH],
            'password' => 'required|string',
        ];
    }
}
