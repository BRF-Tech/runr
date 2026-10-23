<?php

namespace App\Services\GlobalDns;

/**
 * The global zone as RunR wants it on every server (pure: no database, no network), and the
 * PowerDNS API changes that turn a server's zone into it.
 *
 *   <zone>              NS    ns-<region>.<zone>. — one per ready server
 *   ns-<region>.<zone>  A / AAAA  the server's address (the glue the operator also creates in the
 *                       parent zone)
 *   <slug>.<zone>       LUA   A "ifurlup(…)" / AAAA "ifurlup(…)" — one per address family the
 *                       project's regions have
 *
 * The LUA records health-check the region copies of the project from every server: ifurlup() asks
 * http://<health host>/global-health/<slug> on each region address (the region agent answers "ok"
 * while the project's Knative Service is ready there) and answers
 *
 *   ha        groups {primary} then {the other regions}: the primary while it is up, else every
 *             other region that is up (selector all); every address when none is up
 *   proximity one group of every region, selector pickclosest: the region closest to the client
 *             (EDNS client subnet or the resolver's address, GeoIP) among those that are up
 *
 * Only regions whose copy serves a version are listed (GlobalZoneEntry). The SOA and the DNS-01
 * challenge records (_acme-challenge.*, written by cert-manager and copied by GlobalZoneSync) are
 * not RunR's records here: diff() leaves them alone.
 */
final class GlobalZoneBuilder
{
    public const CHALLENGE_PREFIX = '_acme-challenge.';

    /**
     * @param  list<array{ns: string, addresses: list<string>}>  $servers  ready servers
     * @param  list<GlobalZoneEntry>  $projects
     * @param  array{ttl: int, health_host: string, timeout: int, minimum_failures: int}  $options
     * @return array<string, array{name: string, type: string, ttl: int, records: list<string>}> "name|type" =>
     */
    public static function build(string $zone, array $servers, array $projects, array $options): array
    {
        $zone = self::absolute($zone);
        $ttl = max(1, (int) $options['ttl']);
        $rrsets = [];

        $nameservers = [];
        foreach ($servers as $server) {
            $ns = self::absolute($server['ns']);
            $nameservers[] = $ns;
            [$v4, $v6] = self::families($server['addresses']);
            if ($v4 !== []) {
                $rrsets["{$ns}|A"] = ['name' => $ns, 'type' => 'A', 'ttl' => 3600, 'records' => $v4];
            }
            if ($v6 !== []) {
                $rrsets["{$ns}|AAAA"] = ['name' => $ns, 'type' => 'AAAA', 'ttl' => 3600, 'records' => $v6];
            }
        }
        sort($nameservers);
        if ($nameservers !== []) {
            $rrsets["{$zone}|NS"] = ['name' => $zone, 'type' => 'NS', 'ttl' => 3600, 'records' => array_values(array_unique($nameservers))];
        }

        foreach ($projects as $project) {
            $records = self::projectRecords($project, $options);
            if ($records === []) {
                continue;
            }
            $name = self::absolute($project->slug.'.'.rtrim($zone, '.'));
            $rrsets["{$name}|LUA"] = ['name' => $name, 'type' => 'LUA', 'ttl' => $ttl, 'records' => $records];
        }

        ksort($rrsets);

        return $rrsets;
    }

    /**
     * The LUA record contents of one project: "A \"…\"" for its IPv4 addresses, "AAAA \"…\"" for
     * its IPv6 ones.
     *
     * @param  array{health_host: string, timeout: int, minimum_failures: int}  $options
     * @return list<string>
     */
    public static function projectRecords(GlobalZoneEntry $project, array $options): array
    {
        $url = 'http://'.$options['health_host'].'/global-health/'.$project->slug;
        $records = [];

        foreach (['A' => 0, 'AAAA' => 1] as $type => $family) {
            $groups = [];
            foreach ($project->regions as $region) {
                $addresses = self::families($region['addresses'])[$family];
                if ($addresses === []) {
                    continue;
                }
                if ($project->mode === 'proximity') {
                    $groups[0] = array_merge($groups[0] ?? [], $addresses);
                } elseif ($region['primary']) {
                    $groups['primary'] = $addresses;
                } else {
                    $groups['others'] = array_merge($groups['others'] ?? [], $addresses);
                }
            }

            if ($project->mode !== 'proximity') {
                $groups = array_values(array_filter([$groups['primary'] ?? [], $groups['others'] ?? []]));
            }
            if ($groups === []) {
                continue;
            }

            $selector = $project->mode === 'proximity' ? 'pickclosest' : 'all';
            $records[] = $type.' "'.self::ifurlup($url, array_values($groups), $selector, $options).'"';
        }

        return $records;
    }

    /**
     * ifurlup('<url>', {{'a', 'b'}, {'c'}}, {selector='…', backupSelector='…', …}). The values are
     * IP addresses, a URL of RunR's own host name and fixed words: nothing a project could set.
     *
     * @param  list<list<string>>  $groups
     * @param  array{timeout: int, minimum_failures: int}  $options
     */
    public static function ifurlup(string $url, array $groups, string $selector, array $options): string
    {
        $sets = array_map(
            fn (array $group) => '{'.implode(', ', array_map(fn (string $ip) => "'{$ip}'", array_values(array_unique($group)))).'}',
            $groups,
        );

        $opts = [
            "selector='{$selector}'",
            "backupSelector='{$selector}'",
            'timeout='.max(1, (int) $options['timeout']),
            'minimumFailures='.max(1, (int) $options['minimum_failures']),
            // the region agent answers "ok": a 200 from anything else (a default page) is no copy
            "stringmatch='ok'",
        ];

        return "ifurlup('{$url}', {".implode(', ', $sets).'}, {'.implode(', ', $opts).'})';
    }

    /**
     * The PATCH rrsets that turn $current (the API's zone rrsets) into $desired: REPLACE of every
     * rrset that is missing or differs, DELETE of every other rrset the zone has — except the SOA
     * and the DNS-01 challenge records.
     *
     * @param  array<string, array{name: string, type: string, ttl: int, records: list<string>}>  $desired
     * @param  list<array<string, mixed>>  $current
     * @return list<array<string, mixed>>
     */
    public static function diff(array $desired, array $current): array
    {
        $have = [];
        foreach ($current as $rrset) {
            $name = strtolower((string) ($rrset['name'] ?? ''));
            $type = strtoupper((string) ($rrset['type'] ?? ''));
            if ($type === 'SOA' || self::isChallenge($name)) {
                continue;
            }
            $records = [];
            foreach ((array) ($rrset['records'] ?? []) as $record) {
                $records[] = self::normalize((string) ($record['content'] ?? ''));
            }
            sort($records);
            $have["{$name}|{$type}"] = ['ttl' => (int) ($rrset['ttl'] ?? 0), 'records' => $records];
        }

        $changes = [];
        foreach ($desired as $key => $rrset) {
            $records = array_map([self::class, 'normalize'], $rrset['records']);
            sort($records);
            $existing = $have[$key] ?? null;
            if ($existing !== null && $existing['records'] === $records && $existing['ttl'] === $rrset['ttl']) {
                continue;
            }
            $changes[] = self::replace($rrset['name'], $rrset['type'], $rrset['ttl'], $rrset['records']);
        }

        foreach ($have as $key => $_) {
            if (! isset($desired[$key])) {
                [$name, $type] = explode('|', $key, 2);
                $changes[] = ['name' => $name, 'type' => $type, 'changetype' => 'DELETE'];
            }
        }

        return $changes;
    }

    /**
     * The challenge records of a zone (TXT rrsets under _acme-challenge.), name => sorted contents.
     *
     * @param  list<array<string, mixed>>  $rrsets
     * @return array<string, array{ttl: int, records: list<string>}>
     */
    public static function challenges(array $rrsets): array
    {
        $out = [];
        foreach ($rrsets as $rrset) {
            $name = strtolower((string) ($rrset['name'] ?? ''));
            if (strtoupper((string) ($rrset['type'] ?? '')) !== 'TXT' || ! self::isChallenge($name)) {
                continue;
            }
            $records = [];
            foreach ((array) ($rrset['records'] ?? []) as $record) {
                $records[] = (string) ($record['content'] ?? '');
            }
            sort($records);
            $out[$name] = ['ttl' => (int) ($rrset['ttl'] ?? 60), 'records' => $records];
        }
        ksort($out);

        return $out;
    }

    /**
     * The PATCH rrsets that make a server's challenge records equal to the update server's.
     *
     * @param  array<string, array{ttl: int, records: list<string>}>  $source
     * @param  array<string, array{ttl: int, records: list<string>}>  $target
     * @return list<array<string, mixed>>
     */
    public static function challengeDiff(array $source, array $target): array
    {
        $changes = [];
        foreach ($source as $name => $rrset) {
            if (($target[$name]['records'] ?? null) !== $rrset['records']) {
                $changes[] = self::replace($name, 'TXT', $rrset['ttl'], $rrset['records']);
            }
        }
        foreach ($target as $name => $_) {
            if (! isset($source[$name])) {
                $changes[] = ['name' => $name, 'type' => 'TXT', 'changetype' => 'DELETE'];
            }
        }

        return $changes;
    }

    public static function isChallenge(string $name): bool
    {
        return str_starts_with(strtolower($name), self::CHALLENGE_PREFIX);
    }

    /**
     * @param  list<string>  $records
     */
    private static function replace(string $name, string $type, int $ttl, array $records): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'ttl' => $ttl,
            'changetype' => 'REPLACE',
            'records' => array_map(fn (string $content) => ['content' => $content, 'disabled' => false], $records),
        ];
    }

    /**
     * IPv4 and IPv6 addresses of a list, canonical and sorted; anything else is dropped.
     *
     * @param  list<string>  $addresses
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function families(array $addresses): array
    {
        $v4 = [];
        $v6 = [];
        foreach ($addresses as $address) {
            $packed = @inet_pton(trim($address));
            if ($packed === false) {
                continue;
            }
            $ip = (string) inet_ntop($packed);
            if (strlen($packed) === 4) {
                $v4[] = $ip;
            } else {
                $v6[] = $ip;
            }
        }
        $v4 = array_values(array_unique($v4));
        $v6 = array_values(array_unique($v6));
        sort($v4);
        sort($v6);

        return [$v4, $v6];
    }

    public static function absolute(string $name): string
    {
        return rtrim(strtolower($name), '.').'.';
    }

    private static function normalize(string $content): string
    {
        return trim(preg_replace('/\s+/', ' ', $content) ?? $content);
    }
}
