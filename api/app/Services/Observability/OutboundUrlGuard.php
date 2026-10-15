<?php

namespace App\Services\Observability;

use App\Services\Dns\DnsResolver;

/**
 * URLs RunR calls on behalf of an organization (webhook / Slack / Teams channels, uptime checks of
 * an absolute URL) must not reach RunR's own network: http(s) only, no credentials in the URL,
 * and the host — a literal address or every address its name resolves to — outside loopback,
 * private, link-local, CGNAT, multicast and reserved ranges. A deployment that monitors private
 * targets turns that off (observability.uptime.allow_private_targets / alerts.allow_private_targets).
 */
class OutboundUrlGuard
{
    public function __construct(private DnsResolver $dns) {}

    /**
     * Null when the URL may be called, otherwise the reason (translated).
     */
    public function check(string $url, bool $allowPrivate = false, bool $requireHttps = false): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return __('observability.url.invalid');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, $requireHttps ? ['https'] : ['http', 'https'], true)) {
            return __($requireHttps ? 'observability.url.https_required' : 'observability.url.invalid_scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return __('observability.url.credentials');
        }

        if ($allowPrivate) {
            return null;
        }

        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? null : __('observability.url.private');
        }

        $lower = strtolower($host);
        if ($lower === 'localhost' || str_ends_with($lower, '.localhost') || str_ends_with($lower, '.local')
            || str_ends_with($lower, '.internal') || str_ends_with($lower, '.svc') || str_contains($lower, '.svc.')) {
            return __('observability.url.private');
        }

        $addresses = $this->dns->addresses($host);
        if ($addresses === []) {
            return __('observability.url.unresolvable');
        }
        foreach ($addresses as $ip) {
            if (! self::isPublicIp($ip)) {
                return __('observability.url.private');
            }
        }

        return null;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Ranges PHP's flags leave out: CGNAT, benchmarking, multicast, IPv6 ULA / link-local / mapped
        foreach (['100.64.0.0/10', '169.254.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '0.0.0.0/8', 'fc00::/7', 'fe80::/10', 'ff00::/8', '::ffff:0:0/96'] as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
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
