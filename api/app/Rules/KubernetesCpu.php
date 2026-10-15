<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A CPU quantity as it goes into the container resources: millicores ("250m") or cores with at
 * most three decimals ("0.5", "2"), greater than zero.
 */
class KubernetesCpu implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $s = is_scalar($value) ? trim((string) $value) : '';

        if (preg_match('/^\d+m$/', $s)) {
            $valid = (int) $s > 0;
        } else {
            $valid = preg_match('/^\d+(\.\d{1,3})?$/', $s) && (float) $s > 0;
        }

        if (! $valid) {
            $fail(__('api.project.cpu_invalid'));
        }
    }
}
