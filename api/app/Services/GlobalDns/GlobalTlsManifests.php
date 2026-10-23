<?php

namespace App\Services\GlobalDns;

/**
 * The global zone's wildcard certificate in a region (docs/global-projects.md), in the region's
 * project namespace next to the global routes that use it:
 *
 *   Secret       runr-global-dns-tsig  the TSIG secret cert-manager signs its updates with
 *   Issuer       runr-global-dns       ACME, DNS-01 through RFC 2136 to the update server
 *                                      (GlobalDnsZone::updateServer) for the zone only
 *   Certificate  runr-global-wildcard  *.<zone> into Secret runr-global-wildcard
 *
 * RunR has never used DNS-01 before: custom domains get one Certificate per host from the
 * operator's ClusterIssuer (ProjectDomainManifests). Here the zone is RunR's own, so one
 * wildcard covers every global project of the region, and no project route waits for a
 * certificate of its own.
 */
final class GlobalTlsManifests
{
    public const TSIG_SECRET = 'runr-global-dns-tsig';

    public const ISSUER = 'runr-global-dns';

    public const CERTIFICATE = 'runr-global-wildcard';

    public const API = 'cert-manager.io/v1';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/component' => 'global-dns'];
    }

    /**
     * @param  array{zone: string, nameserver: string, tsig_key_name: string, tsig_secret: string, acme_server: string, acme_email: ?string}  $options
     * @return list<array>
     */
    public static function all(string $namespace, array $options): array
    {
        return [
            self::tsigSecret($namespace, $options['tsig_secret']),
            self::issuer($namespace, $options),
            self::certificate($namespace, $options['zone']),
        ];
    }

    public static function tsigSecret(string $namespace, string $secret): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => self::TSIG_SECRET, 'namespace' => $namespace, 'labels' => self::labels()],
            'type' => 'Opaque',
            'stringData' => ['secret' => $secret],
        ];
    }

    /**
     * @param  array{zone: string, nameserver: string, tsig_key_name: string, acme_server: string, acme_email: ?string}  $options
     */
    public static function issuer(string $namespace, array $options): array
    {
        $rfc2136 = [
            'nameserver' => $options['nameserver'],
            'tsigKeyName' => $options['tsig_key_name'],
            'tsigAlgorithm' => 'HMACSHA256',
            'tsigSecretSecretRef' => ['name' => self::TSIG_SECRET, 'key' => 'secret'],
        ];
        $acme = [
            'server' => $options['acme_server'],
            'privateKeySecretRef' => ['name' => self::ISSUER.'-account'],
            'solvers' => [
                [
                    'selector' => ['dnsZones' => [$options['zone']]],
                    'dns01' => ['rfc2136' => $rfc2136],
                ],
                // a custom domain of a global project: its owner points _acme-challenge.<domain>
                // at a name under _acme-challenge. of the zone (GlobalDomains::challengeTarget);
                // cert-manager follows the CNAME and writes the TXT record there — every region
                // issues the domain's certificate itself, whichever region the ACME CA reaches
                [
                    'dns01' => ['cnameStrategy' => 'Follow', 'rfc2136' => $rfc2136],
                ],
            ],
        ];
        if (($options['acme_email'] ?? '') !== '' && $options['acme_email'] !== null) {
            $acme['email'] = $options['acme_email'];
        }

        return [
            'apiVersion' => self::API,
            'kind' => 'Issuer',
            'metadata' => ['name' => self::ISSUER, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => ['acme' => $acme],
        ];
    }

    public static function certificate(string $namespace, string $zone): array
    {
        return [
            'apiVersion' => self::API,
            'kind' => 'Certificate',
            'metadata' => ['name' => self::CERTIFICATE, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => [
                'secretName' => self::CERTIFICATE,
                'dnsNames' => ['*.'.$zone],
                'issuerRef' => ['name' => self::ISSUER, 'kind' => 'Issuer', 'group' => 'cert-manager.io'],
                'secretTemplate' => ['labels' => self::labels()],
            ],
        ];
    }

    /**
     * Hash of the objects as wanted (the TSIG secret included): the sync applies them again only
     * when it changes.
     *
     * @param  array<string, mixed>  $options
     */
    public static function specHash(string $namespace, array $options): string
    {
        return hash('sha256', (string) json_encode([$namespace, $options, self::VERSION]));
    }

    /** What the objects look like: a new version applies them again in every region. */
    public const VERSION = 2;

    /**
     * State of the Certificate: [status, message, notAfter] — ready, pending (with cert-manager's
     * reason) or error.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    public static function state(?array $certificate): array
    {
        if ($certificate === null) {
            return ['pending', 'The certificate has not been created yet', null];
        }

        foreach ($certificate['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') !== 'Ready') {
                continue;
            }
            if (($condition['status'] ?? '') === 'True') {
                return ['ready', null, $certificate['status']['notAfter'] ?? null];
            }

            $reason = (string) ($condition['message'] ?? $condition['reason'] ?? '');

            return [str_contains(strtolower((string) ($condition['reason'] ?? '')), 'fail') ? 'error' : 'pending', $reason !== '' ? $reason : null, null];
        }

        return ['pending', null, null];
    }
}
