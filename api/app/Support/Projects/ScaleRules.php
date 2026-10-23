<?php

namespace App\Support\Projects;

use App\Rules\ScaleScheduleCron;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Validation of a project's scale settings shared by the project requests (StoreProjectRequest,
 * UpdateProjectRequest, DeployProjectRequest), runr.yaml (RunrYamlApplier) and the preview of a
 * timed warmer (ScaleScheduleController):
 *
 *  - min_scale may not be above max_scale (with what the request leaves unchanged: the stored
 *    value, a new project's default max_scale of 1) — Knative refuses such a revision
 *  - the timed warmer (config.scale_schedule): at most ScaleSchedule::MAX_ROWS rows of a
 *    five-field cron and a count from 0 to ScaleSchedule::MAX_COUNT, never above max_scale; a
 *    schedule trigger (CronJob) has no Knative Service to keep warm
 */
final class ScaleRules
{
    /**
     * Rules of a scale_schedule field and its rows.
     *
     * @param  Closure(): int  $maxScale  the max_scale the project will have (0 = none)
     * @param  Closure(): ?string  $trigger  the trigger type the project will have
     * @return array<string, array<int, mixed>>
     */
    public static function schedule(string $field, Closure $maxScale, ?Closure $trigger = null): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.ScaleSchedule::MAX_ROWS, function (string $attribute, mixed $value, Closure $fail) use ($trigger) {
                if (is_array($value) && $value !== [] && $trigger && $trigger() === 'schedule') {
                    $fail(__('api.project.scale_schedule_not_http'));
                }
            }],
            "{$field}.*" => ['array'],
            "{$field}.*.cron" => ['required', 'string', 'max:120', new ScaleScheduleCron],
            "{$field}.*.min_scale" => ['required', function (string $attribute, mixed $value, Closure $fail) use ($maxScale) {
                $count = filter_var($value, FILTER_VALIDATE_INT);
                if ($count === false || $count < 0 || $count > ScaleSchedule::MAX_COUNT) {
                    $fail(__('api.project.scale_schedule_count_invalid', ['max' => ScaleSchedule::MAX_COUNT]));

                    return;
                }
                $max = $maxScale();
                if ($max > 0 && $count > $max) {
                    $fail(__('api.project.scale_schedule_above_max', ['count' => $count, 'max' => $max]));
                }
            }],
        ];
    }

    /**
     * min_scale ≤ max_scale with the effective values; the error goes on the field the request
     * changed (min first).
     */
    public static function checkMinMax(Validator $validator, mixed $min, mixed $max, string $minField, string $maxField, bool $minChanged): void
    {
        if (! is_numeric($min) || ! is_numeric($max) || (int) $max < 1 || (int) $min <= (int) $max) {
            return;
        }

        $validator->errors()->add($minChanged ? $minField : $maxField, __('api.project.min_above_max', ['min' => (int) $min, 'max' => (int) $max]));
    }
}
