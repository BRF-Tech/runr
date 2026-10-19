<?php

namespace Tests\Feature;

use App\Jobs\CheckMigrationDnsJob;
use App\Models\KnativeRegion;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\RegionDnsCheck;
use App\Services\RegionMigrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The DNS check of a function domain (dns-check.<domain>) against the region's external address:
 * an IP address among the answers, or — for a load balancer with a host name — a CNAME to it or
 * one of its addresses. gethostbyname() against the external address never passed for a host
 * name, so a region behind such a load balancer never showed DNS resolved and a migration to it
 * waited the full 48 hours.
 */
class RegionDnsCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @var object{cnames: array<string, list<string>>, addresses: array<string, list<string>>} */
    private object $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new class extends DnsResolver
        {
            public array $cnames = [];

            public array $addresses = [];

            public function cname(string $name): array
            {
                return $this->cnames[$name] ?? [];
            }

            public function addresses(string $name): array
            {
                return $this->addresses[$name] ?? [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);
    }

    private function check(?string $domain, ?string $external): ?bool
    {
        return app(RegionDnsCheck::class)->pointsAt($domain, $external);
    }

    public function test_an_ip_address_is_among_the_answers(): void
    {
        $this->assertNull($this->check('eu.example.test', null), 'no external address yet');
        $this->assertNull($this->check(null, '203.0.113.7'), 'no function domain yet');
        $this->assertFalse($this->check('eu.example.test', '203.0.113.7'), 'no record');

        $this->dns->addresses['dns-check.eu.example.test'] = ['198.51.100.1', '203.0.113.7'];
        $this->assertTrue($this->check('eu.example.test', '203.0.113.7'));
        $this->assertFalse($this->check('eu.example.test', '203.0.113.8'));

        $this->dns->addresses['dns-check.eu.example.test'] = ['2001:db8::7'];
        $this->assertTrue($this->check('eu.example.test', '2001:0db8:0000::0007'), 'IPv6 compared canonically');
    }

    public function test_a_load_balancer_host_name_is_reached_by_cname_or_by_its_addresses(): void
    {
        $lb = 'a1b2c3-123.elb.example-cloud.test';
        $this->assertFalse($this->check('eu.example.test', $lb));

        $this->dns->cnames['dns-check.eu.example.test'] = ['A1B2C3-123.ELB.example-cloud.test.'];
        $this->assertTrue($this->check('eu.example.test', $lb), 'the wildcard CNAME names the load balancer');

        $this->dns->cnames = [];
        $this->dns->addresses['dns-check.eu.example.test'] = ['192.0.2.20', '192.0.2.21'];
        $this->dns->addresses[$lb] = ['192.0.2.21', '192.0.2.22'];
        $this->assertTrue($this->check('eu.example.test', $lb), 'an A record of one of the load balancer addresses');

        $this->dns->addresses[$lb] = ['192.0.2.99'];
        $this->assertFalse($this->check('eu.example.test', $lb));
    }

    public function test_a_migration_to_a_load_balancer_host_name_completes_when_dns_answers(): void
    {
        $region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'eu', 'kubeconfig' => 'apiVersion: v1', 'is_active' => true,
            'knative_settings' => ['knative_domain' => 'eu.example.test'], 'external_ip' => 'lb-1.example-cloud.test',
            'migration_status' => 'dns_check', 'migration_started_at' => now(),
            'migration_old_ip' => '203.0.113.7', 'migration_old_kubeconfig' => 'apiVersion: v1',
        ]);
        $this->dns->cnames['dns-check.eu.example.test'] = ['lb-1.example-cloud.test'];

        $this->assertTrue(app(RegionMigrateService::class)->checkMigrationDns($region));

        (new CheckMigrationDnsJob($region))->handle(app(RegionMigrateService::class));
        $this->assertSame('completed', $region->fresh()->migration_status);
    }
}
