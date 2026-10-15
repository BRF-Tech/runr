<?php

namespace App\Http\Requests\Auth;

use App\Rules\AllowedSignupDomain;
use App\Services\WhiteLabel\CaptchaVerifier;
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

        $rules = [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:64|alpha_dash|unique:users,username',
            'email' => ['required', 'email', 'max:190', 'unique:users,email', new AllowedSignupDomain],
            'password' => 'required|string|min:8|confirmed',
        ];

        // A username held by another address's signup that still waits for its verification
        if ($signup->emailVerification()) {
            $rules['username'] = [
                'required', 'string', 'max:64', 'alpha_dash', 'unique:users,username',
                Rule::unique('pending_signups', 'username')->where(fn ($q) => $q
                    ->where('email', '!=', strtolower(trim((string) $this->input('email'))))
                    ->where('expires_at', '>', now())),
            ];
        }

        if ($signup->captchaEnabled()) {
            $rules['captcha_token'] = ['required', 'string', 'max:4096'];
        }

        if ($signup->requiresTermsAcceptance()) {
            $rules['accept_terms'] = ['accepted'];
        }

        return $rules;
    }

    /**
     * The CAPTCHA is verified with the provider only once every other field is valid: a token is
     * single-use at the provider, so a typo in the form must not burn it.
     */
    public function withValidator(Validator $validator): void
    {
        if (! app(SignupSettings::class)->captchaEnabled()) {
            return;
        }

        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if (! app(CaptchaVerifier::class)->verify((string) $this->input('captcha_token'), $this->ip())) {
                $v->errors()->add('captcha_token', __('whitelabel.signup.captcha_failed'));
            }
        });
    }
}
