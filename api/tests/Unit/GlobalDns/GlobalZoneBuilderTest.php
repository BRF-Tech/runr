<?php

namespace Tests\Unit\GlobalDns;

use App\Services\GlobalDns\GlobalZoneBuilder;
use App\Services\GlobalDns\GlobalZoneEntry;
use PHPUnit\Framework\TestCase;

/**
 * The global zone as RunR writes it to every server (docs/global-projects.md): NS + glue of the
 * servers, one LUA rrset per global project — ifurlup() health checks of the regions' copies, ha
 * with the primary first and the others behind it, proximity with pickclosest() — and the PATCH
 * changes that bring a server's zone there without touching the SOA or the DNS-01 challenges.
 */
class GlobalZoneBuilderTest extends TestCase
{
    private const OPTIONS = ['ttl' => 30, 'health_host' => 'runr-health.g.example.com', 'timeout' => 2, 'minimum_failures' => 2];

    private function servers(): array
    {
        return [
            ['ns' => 'ns-us-1.g.example.com', 'addresses' => ['198.51.100.53']],
            ['ns' => 'ns-eu-1.g.example.com', 'addresses' => ['203.0.113.53', '2001:db8::53']],
        ];
    }

    private function entry(string $mode = 'ha'): GlobalZoneEntry
    {
        return new GlobalZoneEntry('shop', $mode, [
            ['region_id' => 'eu', 'primary' => true, 'addresses' => ['203.0.113.10']],
            ['region_id' => 'us', 'primary' => false, 'addresses' => ['198.51.100.10']],
            ['region_id' => 'ap', 'primary' => false, 'addresses' => ['192.0.2.10', '2001:db8::10']],
        ]);
    }

    public function test_the_zone_names_every_ready_server_with_its_glue(): void
    {
        $zone = GlobalZoneBuilder::build('g.example.com', $this->servers(), [], self::OPTIONS);

        $this->assertSame(['ns-eu-1.g.example.com.', 'ns-us-1.g.example.com.'], $zone['g.example.com.|NS']['records']);
        $this->assertSame(['203.0.113.53'], $zone['ns-eu-1.g.example.com.|A']['records']);
        $this->assertSame(['2001:db8::53'], $zone['ns-eu-1.g.example.com.|AAAA']['records']);
        $this->assertSame(['198.51.100.53'], $zone['ns-us-1.g.example.com.|A']['records']);
        $this->assertArrayNotHasKey('ns-us-1.g.example.com.|AAAA', $zone);
    }

    public function test_ha_answers_the_primary_first_and_the_other_regions_behind_it(): void
    {
        $zone = GlobalZoneBuilder::build('g.example.com', $this->servers(), [$this->entry('ha')], self::OPTIONS);
        $rrset = $zone['shop.g.example.com.|LUA'];

        $this->assertSame(30, $rrset['ttl']);
        $this->assertSame([
            "A \"ifurlup('http://runr-health.g.example.com/global-health/shop', {{'203.0.113.10'}, {'198.51.100.10', '192.0.2.10'}}, {selector='all', backupSelector='all', timeout=2, minimumFailures=2, stringmatch='ok'})\"",
            "AAAA \"ifurlup('http://runr-health.g.example.com/global-health/shop', {{'2001:db8::10'}}, {selector='all', backupSelector='all', timeout=2, minimumFailures=2, stringmatch='ok'})\"",
        ], $rrset['records']);
    }

    public function test_proximity_picks_the_closest_healthy_region(): void
    {
        $records = GlobalZoneBuilder::projectRecords($this->entry('proximity'), self::OPTIONS);

        $this->assertStringContainsString("{{'203.0.113.10', '198.51.100.10', '192.0.2.10'}}", $records[0]);
        $this->assertStringContainsString("selector='pickclosest', backupSelector='pickclosest'", $records[0]);
    }

    public function test_without_a_serving_primary_ha_answers_the_other_regions(): void
    {
        $entry = new GlobalZoneEntry('shop', 'ha', [
            ['region_id' => 'us', 'primary' => false, 'addresses' => ['198.51.100.10']],
        ]);

        $records = GlobalZoneBuilder::projectRecords($entry, self::OPTIONS);

        $this->assertCount(1, $records);
        $this->assertStringContainsString("{{'198.51.100.10'}}", $records[0]);
    }

    public function test_a_project_without_addresses_has_no_record(): void
    {
        $zone = GlobalZoneBuilder::build('g.example.com', $this->servers(), [new GlobalZoneEntry('idle', 'ha', [])], self::OPTIONS);

        $this->assertArrayNotHasKey('idle.g.example.com.|LUA', $zone);
    }

    public function test_the_diff_replaces_what_differs_and_deletes_what_runr_does_not_want(): void
    {
        $desired = GlobalZoneBuilder::build('g.example.com', $this->servers(), [$this->entry()], self::OPTIONS);
        $current = [
            ['name' => 'g.example.com.', 'type' => 'SOA', 'ttl' => 3600, 'records' => [['content' => 'a. b. 1 2 3 4 5']]],
            ['name' => 'g.example.com.', 'type' => 'NS', 'ttl' => 3600, 'records' => [['content' => 'ns-us-1.g.example.com.'], ['content' => 'ns-eu-1.g.example.com.']]],
            ['name' => 'ns-eu-1.g.example.com.', 'type' => 'A', 'ttl' => 3600, 'records' => [['content' => '203.0.113.53']]],
            ['name' => 'stale.g.example.com.', 'type' => 'A', 'ttl' => 60, 'records' => [['content' => '192.0.2.99']]],
            ['name' => '_acme-challenge.g.example.com.', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => '"token"']]],
        ];

        $changes = GlobalZoneBuilder::diff($desired, $current);
        $byKey = [];
        foreach ($changes as $change) {
            $byKey["{$change['name']}|{$change['type']}"] = $change['changetype'];
        }

        // equal rrsets (NS in another order, the eu glue) and the SOA / challenge are left alone
        $this->assertArrayNotHasKey('g.example.com.|NS', $byKey);
        $this->assertArrayNotHasKey('ns-eu-1.g.example.com.|A', $byKey);
        $this->assertArrayNotHasKey('g.example.com.|SOA', $byKey);
        $this->assertArrayNotHasKey('_acme-challenge.g.example.com.|TXT', $byKey);

        $this->assertSame('REPLACE', $byKey['ns-eu-1.g.example.com.|AAAA']);
        $this->assertSame('REPLACE', $byKey['ns-us-1.g.example.com.|A']);
        $this->assertSame('REPLACE', $byKey['shop.g.example.com.|LUA']);
        $this->assertSame('DELETE', $byKey['stale.g.example.com.|A']);
    }

    public function test_a_server_already_in_sync_needs_no_change(): void
    {
        $desired = GlobalZoneBuilder::build('g.example.com', $this->servers(), [$this->entry()], self::OPTIONS);
        $current = array_map(fn (array $r) => [
            'name' => $r['name'], 'type' => $r['type'], 'ttl' => $r['ttl'],
            'records' => array_map(fn (string $c) => ['content' => $c, 'disabled' => false], $r['records']),
        ], array_values($desired));

        $this->assertSame([], GlobalZoneBuilder::diff($desired, $current));
    }

    public function test_the_challenges_follow_the_update_server(): void
    {
        $source = GlobalZoneBuilder::challenges([
            ['name' => '_acme-challenge.g.example.com.', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => '"b"'], ['content' => '"a"']]],
            ['name' => 'shop.g.example.com.', 'type' => 'LUA', 'ttl' => 30, 'records' => []],
        ]);
        $target = GlobalZoneBuilder::challenges([
            ['name' => '_acme-challenge.old.g.example.com.', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => '"x"']]],
        ]);

        $this->assertSame(['_acme-challenge.g.example.com.' => ['ttl' => 60, 'records' => ['"a"', '"b"']]], $source);

        $changes = GlobalZoneBuilder::challengeDiff($source, $target);
        $this->assertSame('REPLACE', $changes[0]['changetype']);
        $this->assertSame('_acme-challenge.g.example.com.', $changes[0]['name']);
        $this->assertSame(['name' => '_acme-challenge.old.g.example.com.', 'type' => 'TXT', 'changetype' => 'DELETE'], $changes[1]);

        $this->assertSame([], GlobalZoneBuilder::challengeDiff($source, $source));
    }

    public function test_addresses_are_split_by_family_and_canonical(): void
    {
        [$v4, $v6] = GlobalZoneBuilder::families(['2001:0db8::0001', '203.0.113.10', 'not-an-ip', '203.0.113.10']);

        $this->assertSame(['203.0.113.10'], $v4);
        $this->assertSame(['2001:db8::1'], $v6);
    }
}
