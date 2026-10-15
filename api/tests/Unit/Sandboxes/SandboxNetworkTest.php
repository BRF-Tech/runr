<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\HostResolver;
use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\SandboxNetwork;
use Tests\TestCase;

/**
 * Egress allowlist parsing: none, CIDRs / addresses (normalized), host names (resolved), ports,
 * IPv6, invalid entries, the always-denied metadata ranges and CIDR containment.
 */
class SandboxNetworkTest extends TestCase
{
    private function network(array $table = []): SandboxNetwork
    {
        return new SandboxNetwork(new class($table) implements HostResolver
        {
            public function __construct(private array $table) {}

            public function resolve(string $host): array
            {
                return $this->table[$host] ?? [];
            }
        });
    }

    public function test_none_in_every_spelling(): void
    {
        foreach ([null, 'none', '', [], ['mode' => 'none'], ['egress_allow' => []]] as $input) {
            $this->assertSame(['mode' => 'none', 'egress_allow' => [], 'rules' => []], $this->network()->parse($input), json_encode($input));
        }
    }

    public function test_cidrs_and_addresses_are_normalized(): void
    {
        $net = $this->network()->parse(['egress_allow' => ['10.1.2.3/8', '203.0.113.7', '2001:db8::1', '2001:db8:abcd::/48']]);

        $this->assertSame('allowlist', $net['mode']);
        $this->assertSame(
            ['10.0.0.0/8', '203.0.113.7/32', '2001:db8::1/128', '2001:db8:abcd::/48'],
            array_column($net['rules'], 'cidr'),
        );
        $this->assertSame([null, null, null, null], array_column($net['rules'], 'port'));
    }

    public function test_a_plain_list_is_an_allowlist(): void
    {
        $net = $this->network()->parse(['192.0.2.0/24']);

        $this->assertSame(['192.0.2.0/24'], $net['egress_allow']);
    }

    public function test_host_names_resolve_to_their_addresses(): void
    {
        $net = $this->network(['files.pythonhosted.org' => ['151.101.1.63', '2a04:4e42::223']])
            ->parse(['egress_allow' => ['Files.PythonHosted.org:443']]);

        $this->assertSame(['files.pythonhosted.org:443'], $net['egress_allow']);
        $this->assertSame([
            ['cidr' => '151.101.1.63/32', 'port' => 443, 'entry' => 'files.pythonhosted.org:443'],
            ['cidr' => '2a04:4e42::223/128', 'port' => 443, 'entry' => 'files.pythonhosted.org:443'],
        ], $net['rules']);
    }

    public function test_ports_on_addresses_cidrs_and_ipv6(): void
    {
        $net = $this->network()->parse(['egress_allow' => ['10.0.0.0/8:5432', '[2001:db8::1]:8443', '192.0.2.10:53']]);

        $this->assertSame([5432, 8443, 53], array_column($net['rules'], 'port'));
        $this->assertSame(['10.0.0.0/8', '2001:db8::1/128', '192.0.2.10/32'], array_column($net['rules'], 'cidr'));
    }

    public function test_invalid_entries_are_refused(): void
    {
        foreach (['not a host', '10.0.0.0/33', '300.1.1.1', 'example.com:0', 'example.com:70000', '-bad-.com', 'localhost', 'unknown.example.com'] as $entry) {
            try {
                $this->network()->parse(['egress_allow' => [$entry]]);
                $this->fail("{$entry} must be refused");
            } catch (SandboxException $e) {
                $this->assertSame(422, $e->status, $entry);
                $this->assertSame('network_invalid', $e->reason, $entry);
                $this->assertArrayHasKey('network', $e->extra['errors']);
            }
        }
    }

    public function test_network_must_be_none_or_an_allowlist(): void
    {
        $this->expectException(SandboxException::class);
        $this->network()->parse('internet');
    }

    public function test_metadata_ranges_can_never_be_allowed(): void
    {
        foreach (['169.254.169.254', '169.254.0.0/16', '169.254.169.254/32:80'] as $entry) {
            try {
                $this->network()->parse([$entry]);
                $this->fail("{$entry} must be refused");
            } catch (SandboxException $e) {
                $this->assertSame('network_invalid', $e->reason);
            }
        }

        // A name that only resolves there is refused as well
        $this->expectException(SandboxException::class);
        $this->network(['metadata.example.com' => ['169.254.169.254']])->parse(['metadata.example.com']);
    }

    public function test_metadata_is_carved_out_of_wider_blocks(): void
    {
        $this->assertSame(['169.254.0.0/16'], SandboxNetwork::exceptFor('0.0.0.0/0'));
        $this->assertSame(['169.254.0.0/16'], SandboxNetwork::exceptFor('169.0.0.0/8'));
        $this->assertSame([], SandboxNetwork::exceptFor('10.0.0.0/8'));
        $this->assertSame(['fd00:ec2::/32'], SandboxNetwork::exceptFor('::/0'));
    }

    public function test_too_many_entries(): void
    {
        config(['sandbox.limits.max_egress_rules' => 2]);

        $this->expectException(SandboxException::class);
        $this->network()->parse(['10.0.0.1', '10.0.0.2', '10.0.0.3']);
    }

    public function test_cidr_containment(): void
    {
        $this->assertTrue(SandboxNetwork::contains('10.0.0.0/8', '10.1.2.0/24'));
        $this->assertTrue(SandboxNetwork::contains('10.0.0.0/8', '10.0.0.0/8'));
        $this->assertFalse(SandboxNetwork::contains('10.1.2.0/24', '10.0.0.0/8'));
        $this->assertFalse(SandboxNetwork::contains('10.0.0.0/8', '11.0.0.0/8'));
        $this->assertFalse(SandboxNetwork::contains('10.0.0.0/8', '2001:db8::/32'));
        $this->assertTrue(SandboxNetwork::contains('2001:db8::/32', '2001:db8:1::/48'));
        $this->assertSame('10.0.0.0', SandboxNetwork::networkAddress('10.200.3.4', 8));
        $this->assertSame('192.168.4.0', SandboxNetwork::networkAddress('192.168.5.9', 23));
    }

    public function test_egress_rules_are_empty_for_none(): void
    {
        $this->assertSame([], SandboxNetwork::egressRules(['mode' => 'none', 'rules' => []]));
        $this->assertSame([], SandboxNetwork::egressRules([]));
    }
}
