<?php

namespace App\Services\Sandboxes;

/**
 * Size inputs of the Sandbox API: CPU as "500m", 0.5 or "1"; memory and disk as MiB (512) or a
 * Kubernetes quantity ("512Mi", "1Gi", "2G"). Null for input that is none of these.
 */
final class Sizes
{
    public static function cpuMillicores(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (int) round($value * 1000) : null;
        }

        $value = trim((string) $value);
        if (preg_match('/^(\d+)m$/', $value, $m)) {
            return (int) $m[1] > 0 ? (int) $m[1] : null;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $value)) {
            $m = (int) round((float) $value * 1000);

            return $m > 0 ? $m : null;
        }

        return null;
    }

    public static function mebibytes(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value)) {
            return $value > 0 ? (int) ceil($value) : null;
        }

        $value = trim((string) $value);
        if (preg_match('/^\d+$/', $value)) {
            return (int) $value > 0 ? (int) $value : null;
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)(Ki|Mi|Gi|Ti|K|M|G|T)$/', $value, $m)) {
            return null;
        }

        $bytes = (float) $m[1] * match ($m[2]) {
            'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4,
            'K' => 1000, 'M' => 1000 ** 2, 'G' => 1000 ** 3, 'T' => 1000 ** 4,
        };
        $mib = (int) ceil($bytes / (1024 ** 2));

        return $mib > 0 ? $mib : null;
    }
}
