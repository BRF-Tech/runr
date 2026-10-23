<?php

namespace App\Services\ApiProduct;

use App\Models\Gateway;
use App\Models\ProjectDomain;
use App\Services\Dns\DnsResolver;
use App\Services\GatewayService;
use App\Services\Helpers\DomainName;
use App\Services\ProjectDomainService;

/**
 * The gateway's own domain (docs/api-product.md#custom-domain), e.g. api.example.com instead of
 * <user>.<region>.<base domain>. Like a project domain it is proven by DNS before RunR routes it:
 *
 *   PUT  .../custom-domain         the name waits in custom_domain_pending with a random token
 *   POST .../custom-domain/verify  TXT _runr-challenge.<domain> = runr-verify=<token>, and the
 *                                  name points at the gateway (CNAME to its host, or an address
 *                                  of the region's external IP) → custom_domain, gateway synced
 *
 * Only a verified name is ever written to custom_domain, the column the gateway's IngressRoute
 * serves; an unverified name never reaches the region.
 */
class GatewayDomains
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly ProjectDomainService $projectDomains,
    ) {}

    /** The gateway's default host (GatewayManifestTrait). */
    /**
     * The gateway's default host — the one domain source of the gateway (GatewayManifestTrait::
     * gatewayHost(): <subdomain>.<the region's function domain>), which the IngressRoute, the
     * GatewayResource and the CNAME target of a custom domain all use.
     */
    public function defaultHost(Gateway $gateway): ?string
    {
        $host = app(GatewayService::class)->gatewayHost($gateway);

        return $host !== null ? DomainName::normalize($host) : null;
    }

    public function challengeName(string $domain): string
    {
        return ProjectDomain::CHALLENGE_PREFIX.'.'.$domain;
    }

    public function challengeValue(Gateway $gateway): string
    {
        return ProjectDomain::CHALLENGE_VALUE_PREFIX.$gateway->custom_domain_token;
    }

    /**
     * Records that route the name to the gateway (any one of them).
     *
     * @return list<array{type: string, value: string}>
     */
    public function dnsTargets(Gateway $gateway): array
    {
        $targets = [];
        if ($host = $this->defaultHost($gateway)) {
            $targets[] = ['type' => 'CNAME', 'value' => $host];
        }

        $external = trim((string) $gateway->knativeRegion?->external_ip);
        if ($external !== '') {
            $targets[] = match (true) {
                filter_var($external, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => ['type' => 'A', 'value' => $external],
                filter_var($external, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => ['type' => 'AAAA', 'value' => $external],
                default => ['type' => 'CNAME', 'value' => DomainName::normalize($external)],
            };
        }

        return $targets;
    }

    /** Why $domain cannot be the gateway's domain, or null. */
    public function problem(Gateway $gateway, string $domain): ?string
    {
        if ($this->projectDomains->isReserved($domain)) {
            return __('api.domain.reserved');
        }

        $taken = Gateway::query()
            ->where('id', '!=', $gateway->id)
            ->where(fn ($q) => $q->where('custom_domain', $domain)->orWhere('custom_domain_pending', $domain))
            ->exists();
        if ($taken || ProjectDomain::query()->where('domain', $domain)->exists()) {
            return __('api_product.domain.taken');
        }

        return null;
    }

    public function request(Gateway $gateway, string $domain): Gateway
    {
        if ($gateway->custom_domain === $domain) {
            return $gateway;
        }

        $gateway->update([
            'custom_domain_pending' => $domain,
            'custom_domain_token' => bin2hex(random_bytes(16)),
        ]);

        return $gateway;
    }

    /**
     * Checks the pending name's DNS; on success it becomes the gateway's domain.
     *
     * @return string|null what is still missing, null when the domain is verified
     */
    public function verify(Gateway $gateway): ?string
    {
        $domain = $gateway->custom_domain_pending;
        if (! $domain || ! $gateway->custom_domain_token) {
            return __('api_product.domain.nothing_pending');
        }

        $expected = $this->challengeValue($gateway);
        if (! in_array($expected, $this->dns->txt($this->challengeName($domain)), true)) {
            return __('api.domain.txt_missing', ['name' => $this->challengeName($domain), 'value' => $expected]);
        }

        if (! $this->pointsToGateway($gateway, $domain)) {
            $targets = $this->dnsTargets($gateway);

            return $targets === []
                ? __('api.domain.no_target')
                : __('api.domain.not_pointing', [
                    'domain' => $domain,
                    'targets' => implode(', ', array_map(fn ($t) => "{$t['type']} {$t['value']}", $targets)),
                ]);
        }

        $gateway->update([
            'custom_domain' => $domain,
            'custom_domain_pending' => null,
            'custom_domain_token' => null,
            'custom_domain_verified_at' => now(),
        ]);

        return null;
    }

    public function remove(Gateway $gateway): Gateway
    {
        $gateway->update([
            'custom_domain' => null,
            'custom_domain_pending' => null,
            'custom_domain_token' => null,
            'custom_domain_verified_at' => null,
        ]);

        return $gateway;
    }

    private function pointsToGateway(Gateway $gateway, string $domain): bool
    {
        $hosts = [];
        $addresses = [];
        foreach ($this->dnsTargets($gateway) as $target) {
            if ($target['type'] === 'CNAME') {
                $hosts[] = $target['value'];
            } else {
                $addresses[] = $target['value'];
            }
        }

        if (array_intersect($this->dns->cname($domain), $hosts)) {
            return true;
        }
        foreach ($hosts as $host) {
            array_push($addresses, ...$this->dns->addresses($host));
        }

        $canonical = fn (string $ip) => ($packed = @inet_pton(trim($ip))) === false ? null : inet_ntop($packed);
        $expected = array_filter(array_map($canonical, $addresses));
        $actual = array_filter(array_map($canonical, $this->dns->addresses($domain)));

        return $expected !== [] && (bool) array_intersect($actual, $expected);
    }
}
