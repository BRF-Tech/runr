<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Client IP behind a known chain of proxies, config/trustedproxy.php.
 *
 * Helm layout: client → ingress controller (Traefik) → runr-gateway (Caddy) → runr-api, i.e. two
 * proxies that append to X-Forwarded-For (TRUSTED_PROXY_HOPS=2). The connecting peer is trusted
 * when its address is in TRUSTED_PROXIES; then, walking X-Forwarded-For from the right, at most
 * hops − 1 further entries are trusted as proxies, each only while it is in TRUSTED_PROXIES too.
 * The next entry is the client — whatever a client wrote further left never counts, even when
 * the ingress controller keeps client-sent X-Forwarded-For (a Traefik whose forwardedHeaders
 * trust the client's network, as RunR's region Traefik did before 0.4.1) or the client's own
 * address is private.
 *
 * A peer outside TRUSTED_PROXIES has none of its X-Forwarded-* headers trusted:
 * request()->ip() (per-IP rate limits, audit IPs) is the peer itself.
 *
 * TRUSTED_PROXIES: CIDRs / addresses, comma- or space-separated; "private_ranges" = the
 * private, loopback and link-local ranges (self::PRIVATE_RANGES); "*" = any peer; empty = none.
 */
class TrustProxies extends Middleware
{
    /**
     * "private_ranges": the private, loopback and link-local networks a pod or compose network
     * lives in. A list of its own rather than Symfony's IpUtils::PRIVATE_SUBNETS, which since
     * symfony/http-foundation 7.4.13 also holds publicly routable prefixes (6to4, Teredo, NAT64)
     * and later the documentation, benchmarking and CGNAT ranges: built for SSRF checks, where a
     * longer list is the safe side; for trusting X-Forwarded-For it is not.
     */
    public const PRIVATE_RANGES = [
        '127.0.0.0/8',    // loopback
        '10.0.0.0/8',     // RFC 1918
        '192.168.0.0/16', // RFC 1918
        '172.16.0.0/12',  // RFC 1918
        '169.254.0.0/16', // link-local
        '0.0.0.0/8',      // "this network" (RFC 5735)
        '240.0.0.0/4',    // reserved (RFC 1112)
        '::1/128',        // loopback
        'fc00::/7',       // unique local
        'fe80::/10',      // link-local
        '::/128',         // unspecified
        // No ::ffff:0:0/96: a dual-stack listener (Octane / FrankenPHP, many proxies) reports
        // every IPv4 client as ::ffff:a.b.c.d. An IPv4-mapped address is matched as the IPv4
        // address it carries (inRanges()).
    ];

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $request->setTrustedProxies(
            self::trustedHops(
                $request,
                self::ranges((string) config('trustedproxy.proxies', '')),
                (int) config('trustedproxy.hops', 1),
            ),
            $this->getTrustedHeaderNames(),
        );
    }

    /**
     * The addresses of the proxy hops of this request: the peer and up to $hops − 1 of the
     * right-most X-Forwarded-For entries, all within $ranges. Empty when the peer is not.
     *
     * @param  list<string>  $ranges
     * @return list<string>
     */
    public static function trustedHops(Request $request, array $ranges, int $hops): array
    {
        $peer = (string) $request->server->get('REMOTE_ADDR', '');

        if ($ranges === [] || ! self::inRanges($peer, $ranges)) {
            return [];
        }

        // the addresses as the request carries them: Symfony matches REMOTE_ADDR and the
        // X-Forwarded-For entries against this list as written
        $trusted = [$peer];
        $chain = array_reverse(self::forwardedFor($request));

        for ($i = 0; $i < $hops - 1 && isset($chain[$i]); $i++) {
            $hop = $chain[$i];
            if (! self::inRanges($hop, $ranges)) {
                break;
            }
            $trusted[] = $hop;
        }

        return array_values(array_unique($trusted));
    }

    /**
     * Whether $ip is in $ranges. An IPv4-mapped IPv6 address (::ffff:10.0.0.5) is matched as the
     * IPv4 address it carries (10.0.0.5) and only so: it is a trusted proxy exactly when that
     * IPv4 peer would be, whatever IPv6 range (::/64, ::ffff:0:0/96) also spans it.
     *
     * @param  list<string>  $ranges
     */
    public static function inRanges(string $ip, array $ranges): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false && IpUtils::checkIp(self::unmapped($ip), $ranges);
    }

    /** The IPv4 address an IPv4-mapped IPv6 address carries (::ffff:a.b.c.d → a.b.c.d); any other as is */
    public static function unmapped(string $ip): string
    {
        $bytes = str_contains($ip, ':') ? @inet_pton($ip) : false;
        if (is_string($bytes) && strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bytes, 12));
        }

        return $ip;
    }

    /**
     * TRUSTED_PROXIES → CIDR list.
     *
     * @return list<string>
     */
    public static function ranges(string $value): array
    {
        $ranges = [];

        foreach (preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $ranges = match ($entry) {
                'private_ranges', 'PRIVATE_SUBNETS' => array_merge($ranges, self::PRIVATE_RANGES),
                '*', '**' => array_merge($ranges, ['0.0.0.0/0', '::/0']),
                default => array_merge($ranges, [$entry]),
            };
        }

        return array_values(array_unique($ranges));
    }

    /**
     * X-Forwarded-For entries, left to right, over all header lines; ports and IPv6 brackets
     * stripped like Symfony does.
     *
     * @return list<string>
     */
    private static function forwardedFor(Request $request): array
    {
        $entries = [];

        foreach ($request->headers->all('x-forwarded-for') as $line) {
            foreach (explode(',', (string) $line) as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, '[')) {
                    $entry = substr($entry, 1, (strpos($entry, ']') ?: strlen($entry)) - 1);
                } elseif (substr_count($entry, ':') === 1) {
                    $entry = strstr($entry, ':', true);
                }
                if ($entry !== '') {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }
}
