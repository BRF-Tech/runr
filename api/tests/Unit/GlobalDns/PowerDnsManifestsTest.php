<?php

namespace Tests\Unit\GlobalDns;

use App\Services\GlobalDns\GlobalTlsManifests;
use App\Services\GlobalDns\PowerDnsManifests;
use PHPUnit\Framework\TestCase;

/**
 * The PowerDNS server of a region and the zone's wildcard certificate objects
 * (docs/global-projects.md): what RunR applies, as plain arrays.
 */
class PowerDnsManifestsTest extends TestCase
{
    private const OPTIONS = [
        'image' => 'ghcr.io/brf-tech/runr/reqs/pdns-auth:5.0.8',
        'ttl' => 30,
        'health_interval' => 5,
        'geoip_url' => 'https://download.db-ip.com/free/dbip-city-lite-{yyyy}-{mm}.mmdb.gz',
        'tsig_key_name' => 'runr-acme',
        'storage_class' => null,
        'storage_size' => '1Gi',
        'service_annotations' => [],
    ];

    /** @return array<string, array> kind/name => manifest */
    private function objects(array $options = []): array
    {
        $out = [];
        foreach (PowerDnsManifests::all('runr-global-dns', 'secret-key', $options + self::OPTIONS) as $manifest) {
            $out["{$manifest['kind']}/{$manifest['metadata']['name']}"] = $manifest;
        }

        return $out;
    }

    public function test_the_server_objects_in_apply_order(): void
    {
        $this->assertSame([
            'Namespace/runr-global-dns',
            'Secret/runr-pdns-api',
            'ConfigMap/runr-pdns-config',
            'PersistentVolumeClaim/runr-pdns-data',
            'Deployment/runr-pdns',
            'Service/runr-pdns',
            'Service/runr-pdns-api',
        ], array_keys($this->objects()));
    }

    public function test_dns_is_a_load_balancer_on_udp_and_tcp_53_that_keeps_the_client_address(): void
    {
        $service = $this->objects(['service_annotations' => ['lb.example.com/address' => '203.0.113.53']])['Service/runr-pdns'];

        $this->assertSame('LoadBalancer', $service['spec']['type']);
        $this->assertSame('Local', $service['spec']['externalTrafficPolicy']);
        $this->assertSame([
            ['name' => 'dns-udp', 'port' => 53, 'targetPort' => 5353, 'protocol' => 'UDP'],
            ['name' => 'dns-tcp', 'port' => 53, 'targetPort' => 5353, 'protocol' => 'TCP'],
        ], $service['spec']['ports']);
        $this->assertSame(['lb.example.com/address' => '203.0.113.53'], $service['metadata']['annotations']);

        // the API is ClusterIP only: RunR reaches it through the Kubernetes API server's proxy
        $this->assertSame('ClusterIP', $this->objects()['Service/runr-pdns-api']['spec']['type']);
    }

    public function test_the_server_runs_unprivileged_with_its_api_key_from_the_secret(): void
    {
        $deployment = $this->objects()['Deployment/runr-pdns'];
        $pod = $deployment['spec']['template']['spec'];

        $this->assertSame('Recreate', $deployment['spec']['strategy']['type']);
        $this->assertSame(953, $pod['securityContext']['runAsUser']);
        $this->assertTrue($pod['securityContext']['runAsNonRoot']);
        $this->assertSame(['python3', '/runr/prepare.py'], $pod['initContainers'][0]['command']);
        $env = array_column($pod['initContainers'][0]['env'], 'value', 'name');
        $this->assertSame(self::OPTIONS['geoip_url'], $env['GEOIP_URL']);
        $this->assertSame('url', $env['GEOIP_MODE']);
        $this->assertSame(['name' => 'runr-pdns-api', 'key' => 'api-key'], $pod['containers'][0]['env'][0]['valueFrom']['secretKeyRef']);
        $this->assertSame(['secret-key'], array_values($this->objects()['Secret/runr-pdns-api']['stringData']));
    }

    public function test_the_config_serves_lua_records_and_takes_updates_only_through_the_policy(): void
    {
        $data = $this->objects()['ConfigMap/runr-pdns-config']['data'];

        foreach (['local-port=5353', 'enable-lua-records=yes', 'edns-subnet-processing=yes', 'dnsupdate=yes',
            'lua-dnsupdate-policy-script=/runr/update-policy.lua', 'disable-axfr=yes', 'default-ttl=30', 'lua-health-checks-interval=5'] as $line) {
            $this->assertStringContainsString($line, $data['00-runr.conf']);
        }

        $policy = $data['update-policy.lua'];
        $this->assertStringContainsString('local key = "runr-acme"', $policy);
        $this->assertStringContainsString('pdns.TXT', $policy);
        $this->assertStringContainsString('_acme-challenge.', $policy);

        $this->assertStringContainsString('schema.sqlite3.sql', $data['prepare.py']);
        $this->assertStringContainsString('launch+=geoip', $data['prepare.py']);
    }

    public function test_a_changed_config_rolls_the_pod(): void
    {
        $before = $this->objects()['Deployment/runr-pdns']['spec']['template']['metadata']['annotations']['runr.dev/config-hash'];
        $after = $this->objects(['ttl' => 60])['Deployment/runr-pdns']['spec']['template']['metadata']['annotations']['runr.dev/config-hash'];

        $this->assertNotSame($before, $after);
        $this->assertSame($before, PowerDnsManifests::configHash(self::OPTIONS));
    }

    public function test_the_load_balancer_address_is_its_ip_else_its_host_name(): void
    {
        $this->assertNull(PowerDnsManifests::loadBalancerAddress(null));
        $this->assertNull(PowerDnsManifests::loadBalancerAddress(['status' => ['loadBalancer' => []]]));
        $this->assertSame('203.0.113.53', PowerDnsManifests::loadBalancerAddress(['status' => ['loadBalancer' => ['ingress' => [['ip' => '203.0.113.53']]]]]));
        $this->assertSame('dns.lb.example.com', PowerDnsManifests::loadBalancerAddress(['status' => ['loadBalancer' => ['ingress' => [['hostname' => 'dns.lb.example.com']]]]]));
    }

    public function test_the_wildcard_is_issued_with_dns01_through_rfc2136(): void
    {
        $objects = GlobalTlsManifests::all('fn', [
            'zone' => 'g.example.com',
            'nameserver' => '203.0.113.53:53',
            'tsig_key_name' => 'runr-acme',
            'tsig_secret' => 'c2VjcmV0',
            'acme_server' => 'https://acme.example.com/directory',
            'acme_email' => 'ops@example.com',
        ]);

        [$secret, $issuer, $certificate] = $objects;
        $this->assertSame(['secret' => 'c2VjcmV0'], $secret['stringData']);

        $acme = $issuer['spec']['acme'];
        $this->assertSame('Issuer', $issuer['kind']);
        $this->assertSame('ops@example.com', $acme['email']);
        $this->assertSame(['g.example.com'], $acme['solvers'][0]['selector']['dnsZones']);
        $this->assertSame([
            'nameserver' => '203.0.113.53:53',
            'tsigKeyName' => 'runr-acme',
            'tsigAlgorithm' => 'HMACSHA256',
            'tsigSecretSecretRef' => ['name' => 'runr-global-dns-tsig', 'key' => 'secret'],
        ], $acme['solvers'][0]['dns01']['rfc2136']);
        // custom domains of global projects: their _acme-challenge CNAME into the zone is followed
        $this->assertArrayNotHasKey('selector', $acme['solvers'][1]);
        $this->assertSame('Follow', $acme['solvers'][1]['dns01']['cnameStrategy']);
        $this->assertSame($acme['solvers'][0]['dns01']['rfc2136'], $acme['solvers'][1]['dns01']['rfc2136']);

        $this->assertSame(['*.g.example.com'], $certificate['spec']['dnsNames']);
        $this->assertSame('runr-global-wildcard', $certificate['spec']['secretName']);
        $this->assertSame(['name' => 'runr-global-dns', 'kind' => 'Issuer', 'group' => 'cert-manager.io'], $certificate['spec']['issuerRef']);
    }

    public function test_the_geoip_mode_reaches_the_init_container_and_the_config_hash(): void
    {
        $file = PowerDnsManifests::all('runr-global-dns', 'secret-key', ['geoip_mode' => 'file', 'geoip_url' => ''] + self::OPTIONS);
        $deployment = collect($file)->first(fn (array $m) => $m['kind'] === 'Deployment');
        $env = array_column($deployment['spec']['template']['spec']['initContainers'][0]['env'], 'value', 'name');

        $this->assertSame(['GEOIP_MODE' => 'file', 'GEOIP_URL' => ''], $env);
        // another source restarts the server (it downloads or loads its database at start)
        $this->assertNotSame(PowerDnsManifests::configHash(self::OPTIONS), PowerDnsManifests::configHash(['geoip_mode' => 'file'] + self::OPTIONS));
        $this->assertStringContainsString('GEOIP_STATUS', PowerDnsManifests::prepareScript());
        $this->assertStringContainsString('geoip.mmdb.gz', PowerDnsManifests::prepareScript());
    }

    public function test_the_geoip_state_is_the_last_status_line_of_the_init_log(): void
    {
        $log = implode("\n", [
            'zone database created',
            'GeoIP download failed: https://mirror.internal/geo.mmdb.gz: <urlopen error timed out>',
            'GEOIP_STATUS {"error": "https://mirror.internal/geo.mmdb.gz: timed out", "mode": "url", "source": "https://mirror.internal/geo.mmdb.gz", "status": "missing"}',
            'GEOIP_STATUS {"bytes": 1048576, "mode": "url", "source": "https://mirror.internal/geo.mmdb.gz", "status": "loaded", "updated": "2026-10-22T10:00:00Z"}',
            'GEOIP_STATUS {"status": "bogus"}',
        ]);

        $state = PowerDnsManifests::geoipState($log);
        $this->assertSame('loaded', $state['status']);
        $this->assertSame(1048576, $state['bytes']);
        $this->assertNull(PowerDnsManifests::geoipState("no status here\n"));
    }

    public function test_the_certificate_state_is_read_from_its_ready_condition(): void
    {
        $this->assertSame('pending', GlobalTlsManifests::state(null)[0]);
        $this->assertSame(['ready', null, '2027-01-01T00:00:00Z'], GlobalTlsManifests::state([
            'status' => ['notAfter' => '2027-01-01T00:00:00Z', 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
        ]));
        $this->assertSame(['pending', 'Waiting for DNS-01 challenge propagation', null], GlobalTlsManifests::state([
            'status' => ['conditions' => [['type' => 'Ready', 'status' => 'False', 'reason' => 'DoesNotExist', 'message' => 'Waiting for DNS-01 challenge propagation']]],
        ]));
        $this->assertSame('error', GlobalTlsManifests::state([
            'status' => ['conditions' => [['type' => 'Ready', 'status' => 'False', 'reason' => 'Failed', 'message' => 'rejected']]],
        ])[0]);
    }
}
