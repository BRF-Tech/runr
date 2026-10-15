<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Services\Contracts\KnativeConstants;

/**
 * Whom the region Traefik trusts with PROXY protocol and X-Forwarded-* headers.
 *
 * Nobody by default: Traefik records the TCP peer it sees as the client (X-Forwarded-For,
 * X-Real-Ip) and drops client-sent X-Forwarded-* — a client can neither prepend a PROXY header
 * nor an X-Forwarded-For to choose its address. The region setting ingress_trusted_ips (CIDRs /
 * addresses) names a load balancer or proxy in front of Traefik that sends PROXY protocol or
 * X-Forwarded-For; both trustedIPs lists of the web and websecure entry points get it.
 *
 * Regions on one cluster share its Traefik: it trusts the union of their settings.
 */
final class IngressTrust
{
    public const SETTING = 'ingress_trusted_ips';

    /** Entry points of the region Traefik (KnativeTraefikTrait::traefikHelmValues) */
    public const ENTRYPOINTS = ['web', 'websecure'];

    /** @return list<string> */
    public static function forRegion(KnativeRegion $region): array
    {
        return self::normalize($region->effective_settings[self::SETTING] ?? []);
    }

    /**
     * @param  iterable<KnativeRegion>  $regions
     * @return list<string>
     */
    public static function union(iterable $regions): array
    {
        $ips = [];
        foreach ($regions as $region) {
            $ips = array_merge($ips, self::forRegion($region));
        }

        return self::normalize($ips);
    }

    /**
     * Trimmed, de-duplicated, sorted (a stable order for the rendered args).
     *
     * @return list<string>
     */
    public static function normalize(mixed $ips): array
    {
        $list = array_values(array_unique(array_filter(
            array_map(fn ($ip) => trim((string) $ip), is_array($ips) ? $ips : []),
            fn (string $ip) => $ip !== '',
        )));
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * Traefik chart values of one entry point, for a values file. Trusting nobody leaves the
     * trustedIPs lists out — the chart's default is the empty list: the values file is YAML, where
     * an empty PHP array is written as {} (a map), which the chart's schema refuses for a list
     * ("ports.web.proxyProtocol.trustedIPs: Invalid type. Expected: array, given: object").
     *
     * @param  list<string>  $ips
     */
    public static function portValues(array $ips): array
    {
        $trusted = $ips === [] ? [] : ['trustedIPs' => $ips];

        return [
            'proxyProtocol' => $trusted + ['insecure' => false],
            'forwardedHeaders' => $trusted + ['insecure' => false],
        ];
    }

    /**
     * The same values as `helm --set-json` pairs for an upgrade that reuses the release values:
     * here the lists are always set (JSON keeps [] a list), so trust removed from a region is
     * removed from the reused values too.
     *
     * @param  list<string>  $ips
     * @return array<string, string>
     */
    public static function helmSetJson(array $ips): array
    {
        $trust = ['trustedIPs' => array_values($ips), 'insecure' => false];

        $set = [];
        foreach (self::ENTRYPOINTS as $entryPoint) {
            foreach (['proxyProtocol', 'forwardedHeaders'] as $kind) {
                foreach ($trust as $key => $value) {
                    $set["ports.{$entryPoint}.{$kind}.{$key}"] = json_encode($value, JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return $set;
    }

    /**
     * Container args the Traefik chart renders for these values (empty lists render nothing).
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function renderedArgs(array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        $args = [];
        foreach (self::ENTRYPOINTS as $entryPoint) {
            $args[] = "--entryPoints.{$entryPoint}.forwardedHeaders.trustedIPs=".implode(',', $ips);
            $args[] = "--entryPoints.{$entryPoint}.proxyProtocol.trustedIPs=".implode(',', $ips);
        }
        sort($args, SORT_STRING);

        return $args;
    }

    /**
     * The trust-related args (PROXY protocol, forwarded headers — trustedIPs, insecure) of a
     * Traefik container.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public static function currentArgs(array $args): array
    {
        $trust = array_values(array_filter(
            $args,
            fn ($arg) => is_string($arg) && preg_match('/^--entry[pP]oints\.[^.]+\.(proxyProtocol|forwardedHeaders)\./', $arg) === 1,
        ));
        sort($trust, SORT_STRING);

        return $trust;
    }

    /**
     * The region Traefik Deployment belongs to RunR's Helm release (release "traefik" in the
     * serving namespace — what KnativeTraefikTrait installs).
     */
    public static function isRunrRelease(array $deployment, string $namespace): bool
    {
        $annotations = $deployment['metadata']['annotations'] ?? [];

        return ($annotations['meta.helm.sh/release-name'] ?? '') === KnativeConstants::TRAEFIK_RELEASE
            && ($annotations['meta.helm.sh/release-namespace'] ?? '') === $namespace;
    }

    /** "0.0.0.0/0" / "10.0.0.0/24, 192.0.2.7" / "nobody" — what a Traefik trusts, for messages. */
    public static function describe(array $trustArgs): string
    {
        $ips = [];
        foreach ($trustArgs as $arg) {
            if (preg_match('/\.(?:proxyProtocol|forwardedHeaders)\.trustedIPs=(.*)$/', $arg, $m)) {
                $ips = array_merge($ips, explode(',', $m[1]));
            } elseif (str_ends_with($arg, '.insecure') || str_ends_with($arg, '.insecure=true')) {
                $ips[] = 'everyone (insecure)';
            }
        }
        $ips = array_values(array_unique($ips));

        return $ips === [] ? 'nobody' : implode(', ', $ips);
    }
}
