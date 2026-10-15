<?php

namespace App\Services\Sandboxes;

/**
 * Resolves the host names of a sandbox's egress allowlist to addresses (NetworkPolicies take
 * address blocks, not names). Bound to DnsHostResolver; tests bind a fixed table.
 */
interface HostResolver
{
    /** @return list<string> IPv4 / IPv6 addresses, [] when the name does not resolve */
    public function resolve(string $host): array;
}
