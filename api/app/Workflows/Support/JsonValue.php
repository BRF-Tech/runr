<?php

namespace App\Workflows\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * JSON column holding an arbitrary user value (event data, step input / output, run output).
 * Decodes objects as stdClass so `{}` stays an object and `[]` a list when the value goes back
 * to the function — the array cast would turn every empty object into a list.
 */
class JsonValue implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode((string) $value, false, 512, JSON_BIGINT_AS_STRING);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Recursively converts decoded JSON (stdClass) to arrays, for code that reads the value. */
    public static function toArray(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true);
    }
}
