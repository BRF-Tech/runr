<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A code file name: it becomes a key of the project's ConfigMap (mounted as /app/code/<name>),
 * so it must be a valid ConfigMap key — letters, digits, "-", "_" and "." only (no directories),
 * at most 253 characters, not "." and not starting with "..".
 */
class ConfigMapKey implements ValidationRule
{
    public const PATTERN = '/^[-._a-zA-Z0-9]+$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = is_string($value) ? $value : '';

        if (! preg_match(self::PATTERN, $name) || strlen($name) > 253 || $name === '.' || str_starts_with($name, '..')) {
            $fail(__('api.code.file_name_invalid', ['name' => mb_substr($name, 0, 64)]));
        }
    }
}
