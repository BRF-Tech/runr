<?php

namespace App\Services\Observability;

use App\Services\Dns\DnsResolver;
use App\Services\Helpers\RegionDomains;

/**
 * URLs RunR calls on behalf of an organization (webhook / Slack / Teams channels, uptime checks of
 * an absolute URL, billing webhooks, workflow endpoint overrides, an organization's OIDC identity
 * provider) must not reach RunR's own network: http(s) only, no credentials in the URL, and the
 * host — a literal address or every address its name resolves to — outside loopback, private,
 * link-local, CGNAT, multicast and reserved ranges (isPublicIp()). A deployment that monitors
 * private targets turns that off (observability.uptime.allow_private_targets /
 * alerts.allow_private_targets).
 *
 * check() answers whether a URL may be called (validation when it is saved); target() is the
 * check of the request itself: it resolves the name once and returns the options that pin the
 * request to the addresses it checked (OutboundTarget::options()), so the HTTP client cannot
 * resolve the name again to another address.
 */
class OutboundUrlGuard
{
    /**
     * IPv4 ranges that are not public (RFC 6890 and the IANA special-purpose registry): this
     * network, private, CGNAT, loopback, link-local (cloud metadata 169.254.169.254), IETF protocol
     * assignments, benchmarking, multicast, reserved and broadcast.
     */
    public const BLOCKED_IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /**
     * IPv6: only global unicast (2000::/3) is public — everything else is not: unspecified,
     * loopback, IPv4-translated (::ffff:0:0:0/96), NAT64 local-use (64:ff9b:1::/48), discard
     * (100::/64), unique local (fc00::/7), link-local (fe80::/10), site-local (fec0::/10) and
     * multicast (ff00::/8) among them. Inside 2000::/3 these are not public either: Teredo (its
     * client address is obfuscated, so it cannot be judged), benchmarking, ORCHID, documentation.
     * IPv4-mapped, IPv4-compatible, 6to4 and NAT64 well-known-prefix addresses are judged by the
     * IPv4 address they carry (embeddedIpv4()).
     */
    public const GLOBAL_IPV6 = '2000::/3';

    public const BLOCKED_IPV6 = [
        '2001::/32', '2001:2::/48', '2001:10::/28', '2001:20::/28', '2001:db8::/32', '3fff::/20',
    ];

    public function __construct(private DnsResolver $dns) {}

    /**
     * Null when the URL may be called, otherwise the reason (translated).
     */
    public function check(string $url, bool $allowPrivate = false, bool $requireHttps = false): ?string
    {
        return $this->target($url, $allowPrivate, $requireHttps)->reason;
    }

    /**
     * The check of a request about to be sent: the reason it may not, or the request options
     * (OutboundTarget::options(): redirects off, the pin of the checked addresses). Send the
     * request with exactly these options, right after this call.
     */
    public function target(string $url, bool $allowPrivate = false, bool $requireHttps = false): OutboundTarget
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return OutboundTarget::blocked('invalid');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, $requireHttps ? ['https'] : ['http', 'https'], true)) {
            return OutboundTarget::blocked($requireHttps ? 'https_required' : 'invalid_scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return OutboundTarget::blocked('credentials');
        }

        if ($allowPrivate) {
            return OutboundTarget::direct();
        }

        if (isset($parts['port']) && $parts['port'] < 1) {
            return OutboundTarget::blocked('invalid');
        }

        return $this->host($parts['host'], (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $scheme);
    }

    /**
     * The check of a request to a project's own URL (https://<slug>.<function domain>): RunR's
     * URL, called as it is — unless the function domain is under the organization's own base
     * domain (RegionDomains::organizationControlled()), whose DNS the organization controls: then
     * it is held to target() like a URL its users set.
     */
    public function project(string $url, bool $allowPrivate = false): OutboundTarget
    {
        return $this->organizationControlled($url) ? $this->target($url, $allowPrivate) : OutboundTarget::direct();
    }

    /** The URL's host is under the organization's own base domain (RegionDomains::organizationControlled()). */
    public function organizationControlled(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host !== '' && RegionDomains::organizationControlled($host);
    }

    /**
     * The check of a host RunR connects to (target() of a URL's host; a Git SSH host): a literal
     * address that is public, or a plain DNS name whose every address is — pinned to them.
     * $host as a URL has it (an IPv6 address in brackets); $scheme picks the proxy of the
     * environment that may carry the request (OutboundTarget::proxy()).
     */
    public function host(string $host, int $port, string $scheme = 'https'): OutboundTarget
    {
        $bracketed = str_starts_with($host, '[');
        $host = strtolower(trim($host, '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($bracketed !== (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return OutboundTarget::blocked('invalid');
            }

            return self::isPublicIp($host) ? OutboundTarget::direct() : OutboundTarget::blocked('private');
        }

        // A plain DNS name only: anything else (non-ASCII, percent-encoded, an IPv4 address in
        // another notation such as 2130706433 or 0x7f.1) may mean another host to the HTTP client
        // than to this check
        if ($bracketed || ! self::isHostName($host)) {
            return OutboundTarget::blocked('invalid');
        }

        $name = rtrim($host, '.');
        if ($name === 'localhost' || $name === 'localhost.localdomain' || str_ends_with($name, '.localhost') || str_ends_with($name, '.local')
            || str_ends_with($name, '.internal') || str_ends_with($name, '.svc') || str_contains($name, '.svc.')) {
            return OutboundTarget::blocked('private');
        }

        $addresses = array_values(array_unique($this->dns->addresses($host)));
        if ($addresses === []) {
            return OutboundTarget::blocked('unresolvable');
        }
        foreach ($addresses as $ip) {
            if (! self::isPublicIp($ip)) {
                return OutboundTarget::blocked('private');
            }
        }

        return OutboundTarget::pinned($host, $port, $addresses, $scheme);
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ipv4 = self::embeddedIpv4($ip) ?? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null);
        if ($ipv4 !== null) {
            if (! filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            foreach (self::BLOCKED_IPV4 as $cidr) {
                if (self::inCidr($ipv4, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || ! self::inCidr($ip, self::GLOBAL_IPV6)) {
            return false;
        }
        foreach (self::BLOCKED_IPV6 as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The IPv4 address an IPv6 address stands for, null when it carries none: IPv4-mapped
     * (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d, deprecated; :: and ::1 included), NAT64
     * well-known prefix (64:ff9b::a.b.c.d) and 6to4 (2002:aabb:ccdd::/48). A connection to one of
     * them reaches that IPv4 address, so it is the address to judge.
     */
    public static function embeddedIpv4(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }

        $prefix = substr($bin, 0, 12);
        if ($prefix === str_repeat("\0", 10)."\xff\xff" || $prefix === str_repeat("\0", 12) || $prefix === "\x00\x64\xff\x9b".str_repeat("\0", 8)) {
            return (string) inet_ntop(substr($bin, 12));
        }
        if (substr($bin, 0, 2) === "\x20\x02") {
            return (string) inet_ntop(substr($bin, 2, 4));
        }

        return null;
    }

    /** A DNS name of letters, digits, '-' and '_' whose last label is not numeric (that would be an IPv4 address to curl). */
    private static function isHostName(string $host): bool
    {
        $name = rtrim($host, '.');
        if ($name === '' || strlen($name) > 253 || ! preg_match('/^(?:[a-z0-9_-]{1,63}\.)*[a-z0-9_-]{1,63}$/', $name)) {
            return false;
        }
        $labels = explode('.', $name);

        return ! preg_match('/^(?:0x[0-9a-f]*|[0-9]+)$/', end($labels));
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
    }
}
