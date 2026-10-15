<?php

namespace App\Services\Dns;

/**
 * Thin wrapper around PHP's dns_get_record() for custom-domain verification.
 * Resolved from the container, so tests bind a fake subclass
 * instead of touching real DNS.
 *
 * Every method answers "nothing found" (empty array) on a lookup failure — a missing
 * record and an unreachable resolver are the same thing to the verification flow.
 */
class DnsResolver
{
    /**
     * TXT strings of $name (multi-string records joined, surrounding quotes removed).
     *
     * @return string[]
     */
    public function txt(string $name): array
    {
        $values = [];

        foreach ($this->lookup($name, DNS_TXT) as $record) {
            $value = isset($record['entries']) && is_array($record['entries'])
                ? implode('', $record['entries'])
                : (string) ($record['txt'] ?? '');

            $values[] = trim($value, " \t\"");
        }

        return $values;
    }

    /**
     * CNAME targets of $name, lowercase, without a trailing dot.
     *
     * @return string[]
     */
    public function cname(string $name): array
    {
        $targets = [];

        foreach ($this->lookup($name, DNS_CNAME) as $record) {
            if (! empty($record['target'])) {
                $targets[] = rtrim(strtolower((string) $record['target']), '.');
            }
        }

        return $targets;
    }

    /**
     * IPv4 + IPv6 addresses $name resolves to (a CNAME chain is followed by the resolver).
     *
     * @return string[]
     */
    public function addresses(string $name): array
    {
        $addresses = [];

        foreach ($this->lookup($name, DNS_A) as $record) {
            if (! empty($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }
        }

        foreach ($this->lookup($name, DNS_AAAA) as $record) {
            if (! empty($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        return $addresses;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function lookup(string $name, int $type): array
    {
        try {
            $records = @dns_get_record($name, $type);
        } catch (\Throwable) {
            return [];
        }

        return is_array($records) ? $records : [];
    }
}
