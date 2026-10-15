<?php

namespace App\Support\Images;

/**
 * RunR's image namespace: ghcr.io/brf-tech (0.5.1 and later). RunR 0.5.0 and earlier published
 * the same images — same package names, same tags, same digests — under ghcr.io/runrsh, and an
 * existing installation still holds such references: Helm values reused by `helm upgrade`,
 * database rows (runtime versions, runtime manifests, CaaS images, model services, …) and the
 * workloads RunR deployed into its regions. The upgrade moves every one of them to ghcr.io/brf-tech
 * with this mapping: the database migration and `php artisan images:move-registry`
 * (RegistryMoveRewriter), `region:reconcile` (ImageRegistryStep and the component steps), the
 * Helm chart (template helper runr.image) and the image settings of config/.
 *
 * Only RunR's own namespace moves, by exact prefix: a reference into another registry — a mirror
 * of your own, the region registry's pull-through path {registry}/ghcr.io/… — stays as it is.
 */
final class RegistryMove
{
    /** RunR's image namespace up to 0.5.0. */
    public const FROM = 'ghcr.io/runrsh';

    /** RunR's image namespace. */
    public const TO = 'ghcr.io/brf-tech';

    /**
     * FROM at the start of a token: not behind a registry host or path (`/`), not part of a longer
     * name (`ghcr.io/runrsh-x`, `myghcr.io/…`).
     */
    private const TOKEN = '~(?<![A-Za-z0-9._/@:-])ghcr\.io/runrsh(?![A-Za-z0-9._-])~';

    /**
     * An image reference (or the namespace itself) under FROM → the same reference under TO;
     * anything else is returned as it is.
     */
    public static function ref(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }
        if ($ref === self::FROM) {
            return self::TO;
        }

        return str_starts_with($ref, self::FROM.'/') ? self::TO.substr($ref, strlen(self::FROM)) : $ref;
    }

    /**
     * Every reference under FROM inside a text (a Dockerfile, a runtime.yaml, a script, a list of
     * shim lines) → TO.
     */
    public static function text(string $text): string
    {
        if (! str_contains($text, 'runrsh')) {
            return $text;
        }

        return (string) preg_replace(self::TOKEN, self::TO, $text);
    }

    /** Does $text hold a reference under FROM (as text() maps it)? */
    public static function mentions(?string $text): bool
    {
        return $text !== null && $text !== '' && self::text($text) !== $text;
    }

    /**
     * Every string of a decoded JSON value through text(); arrays and objects keep their shape
     * (an empty object stays an object).
     */
    public static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[is_string($key) ? self::text($key) : $key] = self::value($item);
            }

            return $out;
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $out->{self::text((string) $key)} = self::value($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * The reverse mapping (TO → FROM), for comparing a desired state with what RunR 0.5.0 or
     * earlier deployed: a live object that differs only in the image namespace.
     */
    public static function legacy(mixed $value): mixed
    {
        if (is_string($value)) {
            if (! str_contains($value, 'brf-tech')) {
                return $value;
            }

            return (string) preg_replace('~(?<![A-Za-z0-9._/@:-])ghcr\.io/brf-tech(?![A-Za-z0-9._-])~', self::FROM, $value);
        }
        if (is_array($value)) {
            return array_map([self::class, 'legacy'], $value);
        }

        return $value;
    }
}
