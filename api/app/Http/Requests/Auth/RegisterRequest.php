<?php

namespace App\Http\Requests\Auth;

use App\Rules\AllowedSignupDomain;
use App\Rules\UniqueIgnoringCase;
use App\Services\Captcha\CaptchaGate;
use App\Services\WhiteLabel\SignupSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /** Why authorize() refused: registration_disabled | invite_only */
    private ?string $refusal = null;

    public function authorize(): bool
    {
        $signup = app(SignupSettings::class);

        if (! $signup->registrationEnabled()) {
            $this->refusal = 'registration_disabled';

            return false;
        }

        // Invite-only: accounts come from tenant invitations or the admin's user management
        if ($signup->inviteOnly()) {
            $this->refusal = 'invite_only';

            return false;
        }

        return true;
    }

    protected function failedAuthorization(): void
    {
        $code = $this->refusal ?? 'registration_disabled';

        throw new HttpResponseException(response()->json([
            'message' => __("whitelabel.signup.{$code}"),
            'code' => $code,
        ], 403));
    }

    public function rules(): array
    {
        $signup = app(SignupSettings::class);

        // The user name: letters, digits, "-" and "_" (alpha_dash; never "@", which makes the sign-in
        // read an identifier as an e-mail address first). User name and e-mail address are unique
        // in any letter case: the sign-in matches both case-insensitively (LoginIdentifier)
        $rules = [
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:64', 'alpha_dash', new UniqueIgnoringCase('users', 'username')],
            'email' => ['required', 'email', 'max:190', new UniqueIgnoringCase('users', 'email'), new AllowedSignupDomain],
            'password' => 'required|string|min:8|confirmed',
        ];

        // A username held by another address's signup that still waits for its verification
        if ($signup->emailVerification()) {
            $rules['username'] = [
                'required', 'string', 'max:64', 'alpha_dash', new UniqueIgnoringCase('users', 'username'),
                Rule::unique('pending_signups', 'username')->where(fn ($q) => $q
                    ->where('email', '!=', strtolower(trim((string) $this->input('email'))))
                    ->where('expires_at', '>', now())),
            ];
        }

        if ($signup->requiresTermsAcceptance()) {
            $rules['accept_terms'] = ['accepted'];
        }

        return $rules;
    }

    /**
     * Bot protection of the `register` form (docs/security.md#bot-protection): checked only once
     * every other field is valid — a token is single-use at the provider, so a typo in the form must
     * not burn it. A refusal (422 required / invalid, 409 fallback with a local challenge, 503) is
     * the answer as the gate gives it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $refusal = app(CaptchaGate::class)->check($this, 'register');
            if ($refusal !== null) {
                throw new HttpResponseException($refusal);
            }
        });
    }
}
