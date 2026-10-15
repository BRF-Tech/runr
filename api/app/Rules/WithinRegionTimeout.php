<?php

namespace App\Rules;

use App\Models\KnativeRegion;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A request timeout (seconds) must not exceed the region's request_timeout setting
 * (effective settings, default 300).
 */
class WithinRegionTimeout implements ValidationRule
{
    public function __construct(private readonly ?string $knativeRegionId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->knativeRegionId || ! is_numeric($value)) {
            return;
        }

        $region = KnativeRegion::find($this->knativeRegionId);
        if (! $region) {
            return;
        }

        $max = (int) ($region->effective_settings['request_timeout'] ?? 300);
        if ($max > 0 && (int) $value > $max) {
            $fail(__('api.project.timeout_too_long', ['max' => $max, 'region' => $region->name]));
        }
    }
}
