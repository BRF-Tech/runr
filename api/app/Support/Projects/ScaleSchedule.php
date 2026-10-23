<?php

namespace App\Support\Projects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The timed warmer of a project (config.scale_schedule, docs/scaling.md): up to MAX_ROWS rows of a
 * five-field cron expression and a container count. A row means "from this moment on, at least
 * min_scale containers" until the next row fires — a step function over time, evaluated in the
 * organization's time zone (OrgTimezone). While a schedule is set it replaces the project's
 * min_scale; scale to zero off still keeps one container, max_scale still caps the workload.
 *
 *  - the value now: the row whose cron fired last (rows firing in the same minute: the larger
 *    count); nothing fired yet: 0
 *  - capacity and fixed-region admission count the schedule's peak (reservedMinScale(): the
 *    largest count, what the workload can hold at any time)
 *  - applied without a new revision: the Knative PodAutoscaler of the latest ready revision gets
 *    autoscaling.knative.dev/min-scale (WarmScaleService), every deploy renders the value of the
 *    moment into the Knative Service template; a saved change applies within a minute
 *  - metering counts the warm containers the warmer applied (WarmScaleService's warm_log); the
 *    preview of a schedule (ScaleScheduleController) shows its week and estimates its month with
 *    week() and containerSeconds()
 */
final class ScaleSchedule
{
    public const MAX_ROWS = 10;

    /** The largest count of a row: the ceiling of min_scale. */
    public const MAX_COUNT = 10;

    /** How far back the last firing of a row is looked for: a Feb 29 cron fires every four years. */
    private const LOOKBACK_DAYS = 1470;

    /** @var array<string, CronFields|false> */
    private static array $parsed = [];

    /**
     * Rows in their stored shape — cron with single spaces, min_scale an int — or null when there
     * are none (the warmer is off). Rows without a cron or a count are dropped.
     *
     * @return list<array{cron: string, min_scale: int}>|null
     */
    public static function normalize(mixed $rows): ?array
    {
        if (is_string($rows)) {
            $rows = json_decode($rows, true);
        }
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        $out = [];
        foreach (array_values($rows) as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (! is_array($row)) {
                continue;
            }

            $cron = is_string($row['cron'] ?? null)
                ? implode(' ', preg_split('/\s+/', trim($row['cron']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                : '';
            $count = $row['min_scale'] ?? $row['min'] ?? null;
            if ($cron === '' || ! is_numeric($count)) {
                continue;
            }

            $out[] = ['cron' => $cron, 'min_scale' => max(0, (int) $count)];
        }

        return $out === [] ? null : $out;
    }

    /**
     * The schedule of a project config (model, array or object), null when the warmer is off.
     *
     * @return list<array{cron: string, min_scale: int}>|null
     */
    public static function rows(object|array|null $config): ?array
    {
        if ($config === null) {
            return null;
        }

        return self::normalize(is_array($config) ? ($config['scale_schedule'] ?? null) : ($config->scale_schedule ?? null));
    }

    /** @param  list<array{cron: string, min_scale: int}>  $rows */
    public static function peak(array $rows): int
    {
        return $rows === [] ? 0 : max(array_column($rows, 'min_scale'));
    }

    /**
     * The min_scale capacity and admission count for a config: the schedule's peak while a
     * schedule is set, else its min_scale.
     */
    public static function reservedMinScale(object|array|null $config): int
    {
        $rows = self::rows($config);
        if ($rows !== null) {
            return self::peak($rows);
        }

        $min = is_array($config) ? ($config['min_scale'] ?? 0) : ($config->min_scale ?? 0);

        return max(0, (int) $min);
    }

    /** The fewest containers a workload keeps: one with scale to zero off, else none. */
    public static function floor(mixed $scaleToZero): int
    {
        if ($scaleToZero === null) {
            return 0;
        }

        return (filter_var($scaleToZero, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $scaleToZero) ? 0 : 1;
    }

    /**
     * The count in effect at $at: the row that fired last at or before it, in $timezone.
     *
     * @param  list<array{cron: string, min_scale: int}>  $rows
     */
    public static function valueAt(array $rows, CarbonInterface $at, string $timezone): int
    {
        $local = CarbonImmutable::instance($at)->setTimezone($timezone);
        $limit = $local->hour * 60 + $local->minute;

        /** @var array{0: int, 1: int, 2: int}|null $best days back, minute of the day, count */
        $best = null;
        foreach ($rows as $row) {
            $fields = self::fields($row['cron']);
            if (! $fields) {
                continue;
            }

            $day = $local->startOfDay();
            for ($d = 0; $d <= self::LOOKBACK_DAYS; $d++) {
                if ($best !== null && $d > $best[0]) {
                    break;
                }
                if ($fields->matchesDay($day)) {
                    $minute = $fields->lastMinuteOfDayUpTo($d === 0 ? $limit : 1439);
                    if ($minute !== null) {
                        if ($best === null || $d < $best[0]
                            || ($d === $best[0] && ($minute > $best[1] || ($minute === $best[1] && $row['min_scale'] > $best[2])))) {
                            $best = [$d, $minute, $row['min_scale']];
                        }

                        break;
                    }
                }
                $day = $day->subDay();
            }
        }

        return $best[2] ?? 0;
    }

    /**
     * The schedule over [$from, $to) as stretches of the same count: [start, end, count] with
     * Unix timestamps, consecutive, the first starting at $from and the last ending at $to. Counts
     * below $floor count as $floor.
     *
     * @param  list<array{cron: string, min_scale: int}>  $rows
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function segments(array $rows, CarbonInterface $from, CarbonInterface $to, string $timezone, int $floor = 0): array
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        if ($toTs <= $fromTs) {
            return [];
        }

        $parsed = [];
        foreach ($rows as $row) {
            if ($fields = self::fields($row['cron'])) {
                $parsed[] = [$fields, $fields->minutesOfDay(), $row['min_scale']];
            }
        }

        $current = max($floor, self::valueAt($rows, $from, $timezone));
        $start = $fromTs;
        $out = [];

        $day = CarbonImmutable::instance($from)->setTimezone($timezone)->startOfDay();
        while ($day->getTimestamp() < $toTs) {
            // minute of the day => the largest count firing then
            $slots = [];
            foreach ($parsed as [$fields, $minutes, $count]) {
                if (! $fields->matchesDay($day)) {
                    continue;
                }
                foreach ($minutes as $k) {
                    if (! isset($slots[$k]) || $slots[$k] < $count) {
                        $slots[$k] = $count;
                    }
                }
            }

            if ($slots !== []) {
                ksort($slots);
                $hours = [];
                foreach ($slots as $k => $count) {
                    $hour = intdiv($k, 60);
                    // the wall-clock hour in the zone (daylight saving time moves it)
                    $hours[$hour] ??= $day->setTime($hour, 0)->getTimestamp();
                    $ts = $hours[$hour] + ($k % 60) * 60;
                    if ($ts <= $fromTs) {
                        continue;
                    }
                    if ($ts >= $toTs) {
                        break;
                    }

                    $value = max($floor, $count);
                    if ($value !== $current) {
                        $out[] = [$start, $ts, $current];
                        $start = $ts;
                        $current = $value;
                    }
                }
            }

            $day = $day->addDay();
        }

        $out[] = [$start, $toTs, $current];

        return $out;
    }

    /**
     * The current week (Monday 00:00 to Monday 00:00 in $timezone) as 7 × 24 cells: the most
     * containers kept warm during each hour.
     *
     * @param  list<array{cron: string, min_scale: int}>  $rows
     * @return array{week_start: CarbonImmutable, timeline: list<list<int>>}
     */
    public static function week(array $rows, CarbonInterface $now, string $timezone, int $floor = 0): array
    {
        $start = CarbonImmutable::instance($now)->setTimezone($timezone)->startOfWeek(CarbonInterface::MONDAY);
        $segments = self::segments($rows, $start, $start->addWeek(), $timezone, $floor);
        $count = count($segments);

        $timeline = [];
        $i = 0;
        for ($d = 0; $d < 7; $d++) {
            $day = $start->addDays($d);
            $cells = [];
            for ($h = 0; $h < 24; $h++) {
                $cellStart = $day->setTime($h, 0)->getTimestamp();
                $cellEnd = $h === 23 ? $day->addDay()->getTimestamp() : $day->setTime($h + 1, 0)->getTimestamp();

                while ($i < $count && $segments[$i][1] <= $cellStart) {
                    $i++;
                }
                $max = 0;
                for ($j = $i; $j < $count && ($segments[$j][0] < $cellEnd || $j === $i); $j++) {
                    $max = max($max, $segments[$j][2]);
                }
                $cells[] = $max;
            }
            $timeline[] = $cells;
        }

        return ['week_start' => $start, 'timeline' => $timeline];
    }

    /**
     * Container-seconds the schedule keeps warm over [$from, $to) (each stretch's count × its
     * length).
     *
     * @param  list<array{cron: string, min_scale: int}>  $rows
     */
    public static function containerSeconds(array $rows, CarbonInterface $from, CarbonInterface $to, string $timezone, int $floor = 0): int
    {
        $total = 0;
        foreach (self::segments($rows, $from, $to, $timezone, $floor) as [$start, $end, $count]) {
            $total += ($end - $start) * $count;
        }

        return $total;
    }

    /** Whether the evaluator understands the expression (CronFields; CronSchedule validates the rest). */
    public static function understands(string $cron): bool
    {
        return self::fields($cron) !== null;
    }

    private static function fields(string $cron): ?CronFields
    {
        if (! array_key_exists($cron, self::$parsed)) {
            if (count(self::$parsed) > 512) {
                self::$parsed = [];
            }
            self::$parsed[$cron] = CronFields::tryParse($cron) ?? false;
        }

        return self::$parsed[$cron] ?: null;
    }
}
