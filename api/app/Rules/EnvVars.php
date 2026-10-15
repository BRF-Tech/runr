<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Environment variables of a project container: a KEY => value map that fits a pod spec.
 * Names must be valid shell identifiers and must not shadow what Knative (PORT, K_*) or RunR's
 * runtime (RUNR_*) set; at most MAX_KEYS entries and MAX_BYTES of names + values.
 */
class EnvVars implements ValidationRule
{
    public const MAX_KEYS = 100;

    public const MAX_BYTES = 32768;

    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public const RESERVED = ['PORT', 'K_SERVICE', 'K_CONFIGURATION', 'K_REVISION', 'RUNR_MODE', 'RUNR_HANDLER', 'RUNR_FILENAME'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value) || array_is_list($value)) {
            $fail(__('api.project.env_not_map'));

            return;
        }

        if (count($value) > self::MAX_KEYS) {
            $fail(__('api.project.env_too_many', ['max' => self::MAX_KEYS]));

            return;
        }

        $bytes = 0;
        foreach ($value as $key => $val) {
            $key = (string) $key;
            if (! preg_match(self::KEY_PATTERN, $key)) {
                $fail(__('api.project.env_key_invalid', ['key' => mb_substr($key, 0, 64)]));

                return;
            }
            if (in_array(strtoupper($key), self::RESERVED, true)) {
                $fail(__('api.project.env_key_reserved', ['key' => $key]));

                return;
            }
            if ($val !== null && ! is_scalar($val)) {
                $fail(__('api.project.env_value_invalid', ['key' => $key]));

                return;
            }
            $bytes += strlen($key) + strlen((string) $val);
        }

        if ($bytes > self::MAX_BYTES) {
            $fail(__('api.project.env_too_large', ['max' => self::MAX_BYTES / 1024]));
        }
    }
}
