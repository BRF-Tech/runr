<?php

namespace App\Services\Helpers;

/**
 * The organization CPU / RAM ceiling of this installation (config/quota.php).
 * Each resource is independent; empty, 0, negative or non-numeric = no ceiling (null).
 */
final class OrganizationCeiling
{
    /** CPU ceiling in millicores, or null when not configured. */
    public static function cpuMillicores(): ?int
    {
        return self::scaled(config('quota.cpu'), 1000);
    }

    /** Memory ceiling in MiB, or null when not configured. */
    public static function memoryMb(): ?int
    {
        return self::scaled(config('quota.memory_gib'), 1024);
    }

    public static function isConfigured(): bool
    {
        return self::cpuMillicores() !== null || self::memoryMb() !== null;
    }

    private static function scaled(mixed $value, int $factor): ?int
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        $value = is_string($value) ? trim($value) : $value;
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $scaled = (int) round((float) $value * $factor);

        return $scaled > 0 ? $scaled : null;
    }
}
