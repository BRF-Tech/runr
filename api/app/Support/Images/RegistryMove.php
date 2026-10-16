<?php

namespace App\Support\Images;

/**
 * RunR's image namespace: ghcr.io/brf-tech/runr (0.5.2 and later). Earlier releases published the
 * same images — same tags, same digests — under two other names, and an existing installation
 * still holds such references: Helm values reused by `helm upgrade`, database rows (runtime
 * versions, runtime manifests, CaaS images, model services, …) and the workloads RunR deployed
 * into its regions:
 *
 *   up to 0.5.0  ghcr.io/runrsh/<name>    every image there is RunR's
 *   0.5.1        ghcr.io/brf-tech/<name>  the organization's namespace: RunR's packages by name
 *                                          (FLAT_PACKAGES), any other package there stays
 *
 * Both move to ghcr.io/brf-tech/runr/<name>, a leading "runr-" or "runr/" of the name dropped:
 * runr-wrapper → wrapper, runr/api → api, exec-python and reqs/alpine as they are. The upgrade
 * moves every reference with this mapping: the database migrations and `php artisan
 * images:move-registry` (RegistryMoveRewriter), `region:reconcile` (ImageRegistryStep and the
 * component steps), the Helm chart (template helper runr.image) and the image settings of config/.
 *
 * Only references that start with one of these namespaces move: a reference into another
 * registry — a mirror of your own, the region registry's pull-through path {registry}/ghcr.io/… —
 * stays as it is, and a reference under TO never moves again.
 */
final class RegistryMove
{
    /** RunR's image namespace up to 0.5.0. */
    public const FROM = 'ghcr.io/runrsh';

    /** The organization's namespace RunR 0.5.1 published its packages in (FLAT_PACKAGES). */
    public const FLAT = 'ghcr.io/brf-tech';

    /** RunR's image namespace. */
    public const TO = 'ghcr.io/brf-tech/runr';

    /** RunR's packages directly under FLAT (0.5.1), by the first segment of their name. */
    private const FLAT_PACKAGES = '~^(?:reqs|exec-.*|runr-.+|containerd-shim-.*|hf-serve|image-upload|ai-gpu-caas|nginx-test)$~';

    /** Names whose "runr-" prefix the move drops (legacy() puts it back). */
    private const RUNR_PREFIXED = ['wrapper', 'agent', 'queue-consumer', 'supply-chain'];

    /** Names that were under "runr/" already (legacy() puts it back). */
    private const RUNR_NESTED = '~^(?:api|ui|model-.+)$~';

    /**
     * An earlier namespace at the start of a token (not behind a registry host or path, not part
     * of a longer name), with the first segment of the name after it.
     */
    private const TOKEN = '~(?<![A-Za-z0-9._/@:-])ghcr\.io/(runrsh|brf-tech)(?:/([A-Za-z0-9._-]+))?(?![A-Za-z0-9._-])~';

    /** TOKEN at the start of a whole reference (ref()). */
    private const REF = '~^ghcr\.io/(runrsh|brf-tech)(?:/([A-Za-z0-9._-]+))?(?![A-Za-z0-9._-])~';

    /** TO at the start of a token, with the first segment of the name after it (legacy()). */
    private const TO_TOKEN = '~(?<![A-Za-z0-9._/@:-])ghcr\.io/brf-tech/runr(?:/([A-Za-z0-9._-]+))?(?![A-Za-z0-9._-])~';

    /**
     * An image reference (or the namespace itself) under FROM or one of RunR's packages under
     * FLAT → the same reference under TO; anything else is returned as it is.
     */
    public static function ref(?string $ref): ?string
    {
        if ($ref === null || ! preg_match(self::REF, $ref, $m)) {
            return $ref;
        }
        $moved = self::moved($m[1], $m[2] ?? '');

        return $moved === null ? $ref : $moved.substr($ref, strlen($m[0]));
    }

    /**
     * Every reference RunR's earlier namespaces hold inside a text (a Dockerfile, a runtime.yaml,
     * a script, a list of shim lines) → TO.
     */
    public static function text(string $text): string
    {
        if (! str_contains($text, 'runrsh') && ! str_contains($text, 'brf-tech')) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::TOKEN,
            fn (array $m) => self::moved($m[1], $m[2] ?? '') ?? $m[0],
            $text,
        );
    }

    /** Does $text hold a reference text() moves? */
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
     * The reverse mapping (TO → $namespace, FROM or FLAT), for comparing a desired state with what
     * an earlier release deployed: a live object that differs only in the image namespace.
     */
    public static function legacy(mixed $value, string $namespace = self::FROM): mixed
    {
        if (is_string($value)) {
            if (! str_contains($value, self::TO)) {
                return $value;
            }

            return (string) preg_replace_callback(self::TO_TOKEN, function (array $m) use ($namespace) {
                $name = $m[1] ?? '';
                if ($name === '') {
                    return $namespace;
                }
                $old = match (true) {
                    in_array($name, self::RUNR_PREFIXED, true) => "runr-{$name}",
                    (bool) preg_match(self::RUNR_NESTED, $name) => "runr/{$name}",
                    default => $name,
                };

                return "{$namespace}/{$old}";
            }, $value);
        }
        if (is_array($value)) {
            return array_map(fn ($item) => self::legacy($item, $namespace), $value);
        }

        return $value;
    }

    /**
     * Where a token of an earlier namespace moves ($name: the first segment of the name after it,
     * "" for the namespace itself); null when it stays.
     */
    private static function moved(string $namespace, string $name): ?string
    {
        if ($namespace === 'runrsh') {
            return $name === '' || $name === 'runr' ? self::TO : self::TO.'/'.self::unprefixed($name);
        }

        return preg_match(self::FLAT_PACKAGES, $name) ? self::TO.'/'.self::unprefixed($name) : null;
    }

    private static function unprefixed(string $name): string
    {
        return str_starts_with($name, 'runr-') ? substr($name, 5) : $name;
    }
}
