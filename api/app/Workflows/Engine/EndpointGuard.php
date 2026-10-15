<?php

namespace App\Workflows\Engine;

/**
 * Validates a user-supplied endpoint override before the engine sends signed requests to it:
 * http(s) only, no credentials, and — unless workflows.allow_private_endpoints — no loopback,
 * private, link-local or otherwise reserved address (literal or resolved), so an override cannot
 * point the engine at the platform's own network.
 */
class EndpointGuard
{
    /** @var \Closure(string): list<string> host → resolved IPs */
    private \Closure $resolver;

    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }

            return $ips;
        };
    }

    /** @throws WorkflowException */
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw WorkflowException::invalid(__('workflows.endpoint_invalid'), 'endpoint_invalid');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw WorkflowException::invalid(__('workflows.endpoint_invalid'), 'endpoint_invalid');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw WorkflowException::invalid(__('workflows.endpoint_credentials'), 'endpoint_invalid');
        }

        if (config('workflows.allow_private_endpoints', false)) {
            return;
        }

        $host = trim($host, '[]');
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true) || str_ends_with(strtolower($host), '.localhost')) {
            throw WorkflowException::invalid(__('workflows.endpoint_private'), 'endpoint_private');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw WorkflowException::invalid(__('workflows.endpoint_private'), 'endpoint_private');
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:10.0.0.1) is judged by its IPv4 address
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // carrier-grade NAT 100.64.0.0/10
            $long = ip2long($ip);

            return ($long & 0xFFC00000) !== (ip2long('100.64.0.0') & 0xFFC00000);
        }

        // unique local fc00::/7
        $first = strtolower(substr($ip, 0, 2));

        return $first !== 'fc' && $first !== 'fd';
    }
}
