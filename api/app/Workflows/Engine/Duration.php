<?php

namespace App\Workflows\Engine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Durations of the workflow protocol: "500ms", "30s", "15m", "2h", "1d", "1w", combinations
 * ("1h30m"), plain numbers (seconds) and ISO-8601 durations ("PT1H30M").
 */
final class Duration
{
    private const UNITS = [
        'ms' => 0.001,
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        'w' => 604800,
    ];

    /** Milliseconds of a duration. */
    public static function milliseconds(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException('Duration must not be negative');
            }

            return (int) round($value * 1000);
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Duration must be a string like "30s", "15m", "1d" or a number of seconds');
        }

        $s = strtolower(trim($value));

        if (is_numeric($s)) {
            return self::milliseconds((float) $s);
        }

        if (str_starts_with($s, 'p')) {
            return self::iso8601(strtoupper($s));
        }

        if (! preg_match('/^(\d+(?:\.\d+)?(ms|s|m|h|d|w))+$/', $s)) {
            throw new InvalidArgumentException("Invalid duration \"{$value}\"");
        }

        preg_match_all('/(\d+(?:\.\d+)?)(ms|s|m|h|d|w)/', $s, $parts, PREG_SET_ORDER);

        $ms = 0.0;
        foreach ($parts as [, $amount, $unit]) {
            $ms += (float) $amount * self::UNITS[$unit] * 1000;
        }

        return (int) round($ms);
    }

    /** Whole seconds of a duration (rounded up, so "500ms" is 1). */
    public static function seconds(mixed $value): int
    {
        return (int) ceil(self::milliseconds($value) / 1000);
    }

    public static function isValid(mixed $value): bool
    {
        try {
            self::milliseconds($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** now + duration. */
    public static function from(mixed $value, ?CarbonInterface $now = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now());

        return $now->addMilliseconds(self::milliseconds($value));
    }

    /** An absolute time: ISO-8601 string, epoch milliseconds or a DateTime. */
    public static function parseInstant(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestampMs((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            // The DateTimeImmutable constructor throws on a string it cannot read; Carbon::parse()
            // also raised a PHP warning (DateTimeImmutable::modify) before failing
            try {
                return CarbonImmutable::instance(new DateTimeImmutable($value));
            } catch (\Throwable) {
                // fall through
            }
        }

        throw new InvalidArgumentException('Invalid time: expected an ISO-8601 string or epoch milliseconds');
    }

    private static function iso8601(string $value): int
    {
        if (! preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $value, $m) || $value === 'P' || $value === 'PT') {
            throw new InvalidArgumentException("Invalid ISO-8601 duration \"{$value}\"");
        }

        $seconds = ((int) ($m[1] ?? 0)) * 604800
            + ((int) ($m[2] ?? 0)) * 86400
            + ((int) ($m[3] ?? 0)) * 3600
            + ((int) ($m[4] ?? 0)) * 60
            + ((float) ($m[5] ?? 0));

        return (int) round($seconds * 1000);
    }
}
