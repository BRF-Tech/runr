<?php

namespace App\Support\Projects;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A five-field cron expression (minute hour day-of-month month day-of-week) as sets of values —
 * the evaluator of the timed warmer (ScaleSchedule), which needs the firings of a whole day or
 * week at once rather than one "next run" at a time.
 *
 * Syntax: what CronSchedule accepts without its macros — numbers, `*` / `?`, ranges (`a-b`),
 * steps (`*\/n`, `a-b/n`, `a/n` = a to the field's end), lists, month names (JAN–DEC) and day
 * names (SUN–SAT, 7 is Sunday too). Day-of-month and day-of-week follow Vixie cron (and the
 * Kubernetes CronJob controller): when both are restricted — neither starts with `*` or `?` — a
 * day matching either one matches; otherwise both must match.
 */
final class CronFields
{
    private const BOUNDS = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    private const DAYS = ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6];

    /**
     * @param  list<int>  $minutes  ascending
     * @param  list<int>  $hours  ascending
     * @param  array<int, true>  $daysOfMonth
     * @param  array<int, true>  $months
     * @param  array<int, true>  $daysOfWeek  0 = Sunday
     */
    private function __construct(
        public readonly array $minutes,
        public readonly array $hours,
        private readonly array $daysOfMonth,
        private readonly array $months,
        private readonly array $daysOfWeek,
        private readonly bool $domRestricted,
        private readonly bool $dowRestricted,
    ) {}

    /** @throws InvalidArgumentException not five valid fields */
    public static function parse(string $expression): self
    {
        $fields = preg_split('/\s+/', trim($expression), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException('A cron expression has five fields');
        }

        $sets = [];
        foreach ($fields as $i => $field) {
            $sets[$i] = self::field($field, $i);
        }

        // 7 is Sunday as well as 0
        if (isset($sets[4][7])) {
            unset($sets[4][7]);
            $sets[4][0] = true;
        }

        $minutes = array_keys($sets[0]);
        $hours = array_keys($sets[1]);
        sort($minutes);
        sort($hours);

        return new self(
            $minutes,
            $hours,
            $sets[2],
            $sets[3],
            $sets[4],
            ! self::unrestricted($fields[2]),
            ! self::unrestricted($fields[4]),
        );
    }

    public static function tryParse(mixed $expression): ?self
    {
        if (! is_string($expression)) {
            return null;
        }

        try {
            return self::parse($expression);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Whether the expression fires on this calendar day (of the timezone $day is in). */
    public function matchesDay(CarbonInterface $day): bool
    {
        if (! isset($this->months[(int) $day->month])) {
            return false;
        }

        $dom = isset($this->daysOfMonth[(int) $day->day]);
        $dow = isset($this->daysOfWeek[(int) $day->dayOfWeek]);

        if ($this->domRestricted && $this->dowRestricted) {
            return $dom || $dow;
        }
        if ($this->domRestricted) {
            return $dom;
        }
        if ($this->dowRestricted) {
            return $dow;
        }

        return true;
    }

    /**
     * Minutes of a matching day the expression fires at (hour × 60 + minute), ascending.
     *
     * @return list<int>
     */
    public function minutesOfDay(): array
    {
        $out = [];
        foreach ($this->hours as $hour) {
            foreach ($this->minutes as $minute) {
                $out[] = $hour * 60 + $minute;
            }
        }

        return $out;
    }

    /** The last minute of a matching day at or before $limit (hour × 60 + minute), null when none. */
    public function lastMinuteOfDayUpTo(int $limit): ?int
    {
        for ($h = count($this->hours) - 1; $h >= 0; $h--) {
            $base = $this->hours[$h] * 60;
            if ($base > $limit) {
                continue;
            }
            for ($m = count($this->minutes) - 1; $m >= 0; $m--) {
                if ($base + $this->minutes[$m] <= $limit) {
                    return $base + $this->minutes[$m];
                }
            }
        }

        return null;
    }

    private static function unrestricted(string $field): bool
    {
        return $field[0] === '*' || $field[0] === '?';
    }

    /**
     * @return array<int, true>
     *
     * @throws InvalidArgumentException
     */
    private static function field(string $field, int $index): array
    {
        [$min, $max] = self::BOUNDS[$index];
        $values = [];

        foreach (explode(',', $field) as $part) {
            if ($part === '') {
                throw new InvalidArgumentException("Empty list item in \"{$field}\"");
            }

            $step = 1;
            $stepped = str_contains($part, '/');
            if ($stepped) {
                [$part, $stepText] = explode('/', $part, 2);
                if (! ctype_digit($stepText) || (int) $stepText < 1) {
                    throw new InvalidArgumentException("Invalid step in \"{$field}\"");
                }
                $step = (int) $stepText;
            }

            if ($part === '*' || $part === '?') {
                [$from, $to] = [$min, $index === 4 ? 6 : $max];
            } elseif (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                $from = self::value($a, $index);
                $to = self::value($b, $index);
                if ($from > $to) {
                    throw new InvalidArgumentException("Descending range in \"{$field}\"");
                }
            } else {
                $from = self::value($part, $index);
                // "a/n": from a to the end of the field
                $to = $stepped ? max($from, $index === 4 ? 6 : $max) : $from;
            }

            for ($v = $from; $v <= $to; $v += $step) {
                $values[$v] = true;
            }
        }

        return $values;
    }

    /** @throws InvalidArgumentException */
    private static function value(string $text, int $index): int
    {
        [$min, $max] = self::BOUNDS[$index];
        $upper = strtoupper($text);

        $value = match (true) {
            ctype_digit($text) => (int) $text,
            $index === 3 && isset(self::MONTHS[$upper]) => self::MONTHS[$upper],
            $index === 4 && isset(self::DAYS[$upper]) => self::DAYS[$upper],
            default => throw new InvalidArgumentException("Invalid value \"{$text}\""),
        };

        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("Value {$value} out of range {$min}-{$max}");
        }

        return $value;
    }
}
