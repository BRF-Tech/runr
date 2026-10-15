<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Size limit in bytes (not characters), e.g. code that ends up in a ConfigMap (1 MiB limit).
 */
class MaxBytes implements ValidationRule
{
    public function __construct(private readonly int $maxBytes) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > $this->maxBytes) {
            $fail(__('api.code.too_large', ['max' => number_format($this->maxBytes), 'size' => number_format(strlen($value))]));
        }
    }
}
