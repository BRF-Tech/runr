<?php

namespace App\Rules;

use App\Services\WhiteLabel\SignupSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Signup e-mail domain allowlist (Settings → Signup → allowed domains; empty = any domain).
 */
class AllowedSignupDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(SignupSettings::class)->isDomainAllowed((string) $value)) {
            $fail(__('whitelabel.signup.domain_not_allowed'));
        }
    }
}
