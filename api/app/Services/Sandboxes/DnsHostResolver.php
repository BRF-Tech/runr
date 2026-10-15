<?php

namespace App\Services\Sandboxes;

/** A and AAAA records through the API host's resolver. */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);
            foreach ($records ?: [] as $record) {
                if (! empty($record[$field])) {
                    $addresses[] = $record[$field];
                }
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            $addresses = $fallback ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
