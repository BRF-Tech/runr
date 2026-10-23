<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;

/**
 * The region setting request_timeout (seconds, 1–3600, default 300): the longest timeout a
 * project of the region may have (WithinRegionTimeout), which its Knative revision gets as
 * timeoutSeconds. For such a request to run to its end, the layers in front of it have to wait
 * as long:
 *
 *  - Knative refuses a revision whose timeoutSeconds exceeds config-defaults
 *    max-revision-timeout-seconds (Knative's default 600): it is raised to the setting
 *    (spec.config.defaults of the KnativeServing resource), never lowered;
 *  - the region Traefik cuts a response after its entry points' writeTimeout (and reading a
 *    request after readTimeout; RunR installed 120 s): both follow the setting plus a margin,
 *    so Knative's own timeout answer (504) arrives first.
 *
 * Regions on one cluster share its Traefik and KnativeServing: they follow the longest setting
 * of the regions there.
 */
final class RequestTimeout
{
    public const SETTING = 'request_timeout';

    public const DEFAULT = 300;

    /** Knative's default max-revision-timeout-seconds */
    public const KNATIVE_DEFAULT_MAX = 600;

    /** RunR's Traefik entry point read / write timeout before it followed the setting */
    public const TRAEFIK_MIN = 120;

    /** Seconds Traefik waits longer than the longest revision timeout */
    public const TRAEFIK_MARGIN = 10;

    public const KNATIVE_KEY = 'max-revision-timeout-seconds';

    /** Entry points of the region Traefik (KnativeTraefikTrait::traefikHelmValues) */
    public const ENTRYPOINTS = ['web', 'websecure'];

    /**
     * The entry points whose timeouts follow the setting on a Traefik with these container args:
     * web, websecure and, where it has one, the private entry point (PrivateIngress) that serves
     * cluster-local routes — a queue-trigger sink waits as long as a public request.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public static function entryPoints(array $args): array
    {
        foreach ($args as $arg) {
            if (preg_match('/^--entry[pP]oints\.'.PrivateIngress::ENTRYPOINT.'\.address=/', $arg)) {
                return [...self::ENTRYPOINTS, PrivateIngress::ENTRYPOINT];
            }
        }

        return self::ENTRYPOINTS;
    }

    public static function forRegion(KnativeRegion $region): int
    {
        $value = (int) ($region->effective_settings[self::SETTING] ?? self::DEFAULT);

        return $value > 0 ? $value : self::DEFAULT;
    }

    /** @param iterable<KnativeRegion> $regions */
    public static function longest(iterable $regions): int
    {
        $longest = 0;
        foreach ($regions as $region) {
            $longest = max($longest, self::forRegion($region));
        }

        return $longest ?: self::DEFAULT;
    }

    /** Traefik read / write timeout (seconds) for revisions of up to $seconds */
    public static function traefikSeconds(int $seconds): int
    {
        return max(self::TRAEFIK_MIN, $seconds + self::TRAEFIK_MARGIN);
    }

    /** Knative max-revision-timeout-seconds for revisions of up to $seconds */
    public static function knativeMax(int $seconds): int
    {
        return max(self::KNATIVE_DEFAULT_MAX, $seconds);
    }

    /** respondingTimeouts of one Traefik entry point (chart values) */
    public static function traefikTransport(int $seconds): array
    {
        $timeout = self::traefikSeconds($seconds).'s';

        return ['readTimeout' => $timeout, 'writeTimeout' => $timeout, 'idleTimeout' => '300s'];
    }

    /**
     * The same as `helm --set-json` pairs for an upgrade that reuses the release values.
     *
     * @param  list<string>  $entryPoints  entryPoints() of the release
     * @return array<string, string>
     */
    public static function helmSetJson(int $seconds, array $entryPoints = self::ENTRYPOINTS): array
    {
        $timeout = json_encode(self::traefikSeconds($seconds).'s');
        $set = [];
        foreach ($entryPoints as $entryPoint) {
            foreach (['readTimeout', 'writeTimeout'] as $key) {
                $set["ports.{$entryPoint}.transport.respondingTimeouts.{$key}"] = $timeout;
            }
        }

        return $set;
    }

    /**
     * The read / write timeouts (seconds) of the entry points in a Traefik container's args,
     * "web.readTimeout" => 120, …; a timeout not set is missing (Traefik's own default applies).
     *
     * @param  list<string>  $args
     * @return array<string, int>
     */
    public static function currentTraefik(array $args): array
    {
        $found = [];
        foreach ($args as $arg) {
            if (preg_match('/^--entry[pP]oints\.(web|websecure|'.PrivateIngress::ENTRYPOINT.')\.transport\.respondingTimeouts\.(readTimeout|writeTimeout)=(\d+)s?$/', $arg, $m)) {
                $found["{$m[1]}.{$m[2]}"] = (int) $m[3];
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Every read / write timeout of the entry points is $wanted seconds.
     *
     * @param  array<string, int>  $current  currentTraefik()
     * @param  list<string>  $entryPoints  entryPoints() of the release
     */
    public static function traefikMatches(array $current, int $wanted, array $entryPoints = self::ENTRYPOINTS): bool
    {
        return count($current) === count($entryPoints) * 2 && min($current) === $wanted && max($current) === $wanted;
    }

    /** "120 s" / "120–300 s" / "not set" — the current timeouts, for messages */
    public static function describe(array $current): string
    {
        if ($current === []) {
            return 'not set';
        }

        return min($current) === max($current) ? min($current).' s' : min($current).'–'.max($current).' s';
    }
}
