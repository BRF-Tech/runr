<?php

namespace App\Services\Helpers;

/**
 * Kubernetes resource quantities ("250m", "0.5", "1", "128Mi", "1.5Gi", "500M", "1e9") as
 * numbers. Suffixes: binary Ki Mi Gi Ti Pi Ei, decimal n u m k M G T P E, or a decimal exponent.
 * Fractions round up like Kubernetes does (Quantity.MilliValue / Value).
 */
final class KubeQuantity
{
    private const BINARY = [
        'Ki' => 1024, 'Mi' => 1048576, 'Gi' => 1073741824,
        'Ti' => 1099511627776, 'Pi' => 1125899906842624, 'Ei' => 1152921504606846976,
    ];

    private const DECIMAL = [
        'n' => 1e-9, 'u' => 1e-6, 'm' => 1e-3, '' => 1.0,
        'k' => 1e3, 'M' => 1e6, 'G' => 1e9, 'T' => 1e12, 'P' => 1e15, 'E' => 1e18,
    ];

    private const PATTERN = '/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))(?:([eE][+-]?\d+)|(Ki|Mi|Gi|Ti|Pi|Ei|n|u|m|k|M|G|T|P|E))?$/D';

    /** Quantity in base units (cores, bytes); 0 for an empty or unparsable value. */
    public static function value(mixed $quantity): float
    {
        if ($quantity === null || is_bool($quantity)) {
            return 0.0;
        }

        if (! preg_match(self::PATTERN, trim((string) $quantity), $m)) {
            return 0.0;
        }

        $number = (float) ($m[1].($m[2] ?? ''));
        $suffix = $m[3] ?? '';

        return $number * (self::BINARY[$suffix] ?? self::DECIMAL[$suffix]);
    }

    /** CPU quantity → millicores ("100m" → 100, "0.5" → 500, "2" → 2000). */
    public static function millicores(mixed $cpu): int
    {
        return (int) ceil(round(self::value($cpu) * 1000, 6));
    }

    /** Memory quantity → bytes ("128Mi" → 134217728, "1G" → 1000000000). */
    public static function bytes(mixed $memory): int
    {
        return (int) ceil(round(self::value($memory), 3));
    }

    /** Bytes → MiB, rounded to the nearest MiB (node allocatable as RunR has always shown it). */
    public static function toMib(int|float $bytes): int
    {
        return (int) round($bytes / 1048576);
    }
}
