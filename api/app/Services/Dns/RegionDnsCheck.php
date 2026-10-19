<?php

namespace App\Services\Dns;

use App\Services\Helpers\DomainName;

/**
 * Whether the wildcard DNS record of a function domain points at the region's external address,
 * checked on dns-check.<domain> from the control plane: the region status check (dns_resolved),
 * the DNS step of a region migration and of a base-domain migration.
 *
 * The external address is what the region Traefik's LoadBalancer Service reports: an IP address,
 * or a host name (a cloud load balancer). For an IP address the record resolves to it (A / AAAA,
 * through any CNAME chain). For a host name the record is a CNAME to it, or resolves to one of
 * its addresses.
 */
class RegionDnsCheck
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * null when there is nothing to check (no domain or no external address yet).
     */
    public function pointsAt(?string $domain, ?string $external): ?bool
    {
        $domain = DomainName::normalize((string) $domain);
        $external = trim((string) $external);
        if ($domain === '' || $external === '') {
            return null;
        }

        $host = 'dns-check.'.$domain;
        $addresses = self::canonical($this->dns->addresses($host));

        if (filter_var($external, FILTER_VALIDATE_IP) !== false) {
            return in_array(self::canonicalIp($external), $addresses, true);
        }

        $target = DomainName::normalize($external);
        if (in_array($target, array_map(DomainName::normalize(...), $this->dns->cname($host)), true)) {
            return true;
        }

        $targetAddresses = self::canonical($this->dns->addresses($target));

        return $addresses !== [] && array_intersect($addresses, $targetAddresses) !== [];
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>
     */
    private static function canonical(array $ips): array
    {
        return array_values(array_filter(array_map(self::canonicalIp(...), $ips)));
    }

    private static function canonicalIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false ? null : inet_ntop($packed);
    }
}
