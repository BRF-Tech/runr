<?php

namespace App\Rules;

use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A schedule the Kubernetes CronJob controller accepts: five fields (minute hour day-of-month
 * month day-of-week) or one of its macros. No seconds field, no CRON_TZ=/TZ= prefix, no Quartz
 * extensions (L, W, #) — the controller's parser rejects them. The finest schedule is therefore
 * once a minute.
 */
class CronSchedule implements ValidationRule
{
    public const MACROS = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('api.project.cron_invalid'));
        }
    }

    public static function isValid(string $value): bool
    {
        $expr = trim($value);

        if (in_array(strtolower($expr), self::MACROS, true)) {
            return true;
        }

        $fields = preg_split('/\s+/', $expr, -1, PREG_SPLIT_NO_EMPTY);
        if (count($fields) !== 5) {
            return false;
        }

        [$minute, $hour, $dom, $month, $dow] = $fields;
        foreach ([$minute, $hour, $dom] as $field) {
            if (! preg_match('#^[0-9*?/,-]+$#', $field)) {
                return false;
            }
        }
        foreach ([$month, $dow] as $field) {
            if (! preg_match('#^[0-9A-Za-z*?/,-]+$#', $field)) {
                return false;
            }
        }
        // Day names (SUN..SAT) contain no "L"; a "L" in the day-of-week is Quartz's "last"
        if (preg_match('/l/i', $dow)) {
            return false;
        }

        return CronExpression::isValidExpression($expr);
    }
}
