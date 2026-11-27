<?php

namespace App\Services\Observability;

/**
 * The proxy of the API's environment for a pinned request (OutboundTarget::options(),
 * KubernetesClient, PinProxy, the git reader and the MCP gateway): the operator's egress proxy
 * carries the request — as a CONNECT to the address the guard checked, never to the name, so the
 * proxy cannot resolve it again — or there is none and the request goes straight to that address.
 *
 * The variables are read the way curl and Go's ProxyFromEnvironment read them: HTTPS_PROXY /
 * https_proxy for https, http_proxy for http (HTTP_PROXY too on the command line only: in a web
 * server's environment a request's Proxy header becomes HTTP_PROXY), unless NO_PROXY / no_proxy
 * names the host (matches()); localhost and loopback addresses never go through a proxy. A proxy
 * URL without a scheme is http://; only http:// and https:// proxies carry a pinned request
 * (CONNECT) — another one (socks…) is not used, and the request goes straight to the address.
 */
final class OutboundProxy
{
    /**
     * The proxy URL for a request to $scheme://$host:$port, null when none applies.
     *
     * @param  array<string, string>|null  $env  the variables to read (tests); null: the process's
     */
    public static function for(string $scheme, string $host, int $port, ?array $env = null): ?string
    {
        $value = self::variable($scheme, $env)[1] ?? null;
        if ($value === null || self::excluded($host, $port, $env)) {
            return null;
        }

        $url = str_contains($value, '://') ? $value : 'http://'.$value;
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * The variable that names the proxy of $scheme requests: [name, value], null when none is set.
     *
     * @param  array<string, string>|null  $env
     * @return array{string, string}|null
     */
    public static function variable(string $scheme, ?array $env = null): ?array
    {
        $names = strtolower($scheme) === 'https'
            ? ['HTTPS_PROXY', 'https_proxy']
            : (PHP_SAPI === 'cli' ? ['HTTP_PROXY', 'http_proxy'] : ['http_proxy']);

        foreach ($names as $name) {
            $value = self::read($name, $env);
            if ($value !== '') {
                return [$name, $value];
            }
        }

        return null;
    }

    /**
     * The host goes through no proxy: localhost, a loopback address, or a host NO_PROXY / no_proxy
     * names.
     *
     * @param  array<string, string>|null  $env
     */
    public static function excluded(string $host, int $port, ?array $env = null): bool
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
        if ($host === 'localhost' || ($ip !== null && self::isLoopback($ip))) {
            return true;
        }
        $noProxy = self::read('NO_PROXY', $env);

        return self::matches($noProxy !== '' ? $noProxy : self::read('no_proxy', $env), $host, $ip, (string) $port);
    }

    /**
     * Whether a NO_PROXY list names the host (Go's httpproxy rules): "*", a domain with its
     * subdomains, ".domain" for subdomains only, an IP or CIDR, each with an optional port.
     */
    public static function matches(string $noProxy, string $host, ?string $ip, string $port): bool
    {
        foreach (explode(',', strtolower($noProxy)) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === '*') {
                return true;
            }
            if (str_contains($entry, '/')) {
                if ($ip !== null && self::inCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            // host:port, [v6]:port, a bare IPv6
            $entryPort = '';
            if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $entry, $m)) {
                $entry = $m[1];
                $entryPort = $m[2] ?? '';
            } elseif (substr_count($entry, ':') === 1) {
                [$entry, $entryPort] = explode(':', $entry, 2);
            }
            if ($entry === '' || ($entryPort !== '' && $entryPort !== $port)) {
                continue;
            }

            if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                if ($ip !== null && inet_pton($entry) === inet_pton($ip)) {
                    return true;
                }

                continue;
            }

            if (str_starts_with($entry, '*.')) {
                $entry = substr($entry, 1);
            }
            $matchHost = ! str_starts_with($entry, '.');
            $suffix = $matchHost ? '.'.$entry : $entry;
            if (str_ends_with($host, $suffix) || ($matchHost && $host === $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function isLoopback(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '127.');
        }
        $packed = inet_pton($ip);

        return $packed === inet_pton('::1') || ($packed !== false && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff\x7f"));
    }

    /** @param  array<string, string>|null  $env */
    private static function read(string $name, ?array $env): string
    {
        $value = $env === null ? getenv($name) : ($env[$name] ?? false);

        return is_string($value) ? trim($value) : '';
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2) + [1 => ''];
        $a = @inet_pton($ip);
        $b = @inet_pton($network);
        if ($a === false || $b === false || strlen($a) !== strlen($b) || ! ctype_digit($bits) || (int) $bits > strlen($a) * 8) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
