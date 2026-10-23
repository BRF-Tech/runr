<?php

namespace App\Rules;

use App\Support\Projects\ScaleSchedule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The cron expression of a timed warmer row (config.scale_schedule.*.cron): five fields the
 * Kubernetes CronJob controller would accept too (CronSchedule) — no @macros, no seconds, no
 * time zone (the rows run in the organization's zone) — that the warmer's evaluator understands
 * (CronFields).
 */
class ScaleScheduleCron implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('api.project.scale_schedule_cron_invalid'));
        }
    }

    public static function isValid(string $value): bool
    {
        $fields = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($fields) === 5 && CronSchedule::isValid($value) && ScaleSchedule::understands(implode(' ', $fields));
    }
}
