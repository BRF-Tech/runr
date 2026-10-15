<?php

namespace App\Rules;

use App\Services\Helpers\DomainName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A project custom domain: lowercase ASCII FQDN, no wildcard, no IP
 * address — see DomainName::isValid(). The request normalizes the value (trim,
 * lowercase, trailing dot) before this rule runs.
 */
class CustomDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('api.domain.invalid'));

            return;
        }

        if (str_contains($value, '*')) {
            $fail(__('api.domain.wildcard'));

            return;
        }

        if (! DomainName::isValid($value)) {
            $fail(__('api.domain.invalid'));
        }
    }
}
