<?php

namespace App\Services\Kubeconfigs;

/**
 * The addresses TenantKubeconfigs checked for an organization's region, carried in the cluster's
 * proxy-url as "runr-pinned:203.0.113.10,2001:db8::10" — RunR's own marker, which KubeconfigPolicy
 * refuses in what a user sends and drops when a pinned kubeconfig is checked again.
 *
 * Every client of RunR reads it and connects to these addresses only:
 *  - KubernetesClient: curl's CURLOPT_RESOLVE pins the server's name, no redirects, no proxy;
 *  - the native exec client (ExecKubeconfig): the socket goes to the first address;
 *  - kubectl and helm (KubeconfigFile): proxy-url becomes a local proxy (PinProxy) that opens
 *    tunnels to the server's host and port only, on these addresses.
 *
 * kubectl and helm refuse a proxy-url of another scheme, so a pinned kubeconfig written to disk
 * without KubeconfigFile connects nowhere.
 */
final class KubeconfigPin
{
    public const SCHEME = 'runr-pinned';

    public static function isMarker(string $proxyUrl): bool
    {
        return str_starts_with($proxyUrl, self::SCHEME.':');
    }

    /** @param  list<string>  $addresses */
    public static function marker(array $addresses): string
    {
        return self::SCHEME.':'.implode(',', $addresses);
    }

    /**
     * The pinned addresses of a cluster entry; null when it carries no pin. A pin with an address
     * that is none (a damaged marker) gives an empty list: nothing may be reached.
     *
     * @param  array<string, mixed>  $cluster
     * @return list<string>|null
     */
    public static function addresses(array $cluster): ?array
    {
        $proxy = $cluster['proxy-url'] ?? null;
        if (! is_string($proxy) || ! self::isMarker($proxy)) {
            return null;
        }

        $addresses = [];
        foreach (explode(',', substr($proxy, strlen(self::SCHEME) + 1)) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return [];
            }
            $addresses[] = $ip;
        }

        return $addresses;
    }

    /**
     * Host and port of a cluster server URL (the host without IPv6 brackets); null when it is not
     * one.
     *
     * @return array{host: string, port: int, bracketed: bool}|null
     */
    public static function hostPort(string $server): ?array
    {
        $parts = parse_url($server);
        if ($parts === false || empty($parts['host']) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = strtolower((string) $parts['host']);

        return [
            'host' => rtrim(trim($host, '[]'), '.'),
            'port' => (int) ($parts['port'] ?? 443),
            'bracketed' => str_starts_with($host, '['),
        ];
    }
}
