<?php

namespace Tests\Feature\Secrets;

use App\Models\RegionSecretSetting;
use App\Services\KubernetesClient;
use App\Services\Observability\OutboundUrlGuard;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\ExternalSecretsEgressStep;
use App\Services\Secrets\External\ExternalSecretsEgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * The External Secrets Operator controller of a platform region (multi-tenant edition) reaches
 * DNS, the API server, the region's OpenBao and public addresses only — the outbound guard's
 * ranges, which RunR checks a store's address against, are what its egress policy denies — so a
 * store whose name answers another address by the time the controller connects cannot reach the
 * cluster's network. region:reconcile applies and repairs the policy, and removes it where it is
 * no longer wanted (single-tenant, a tenant's own region, private targets allowed).
 */
class ExternalSecretsEgressTest extends TestCase
{
    use FakesRegionSecrets, RefreshDatabase;

    private const NP = 'https://k8s.test/apis/networking.k8s.io/v1/namespaces/external-secrets/networkpolicies/runr-eso-egress';

    private const CNP = 'https://k8s.test/apis/cilium.io/v2/namespaces/external-secrets/ciliumnetworkpolicies/runr-eso-egress';

    /** @var array<string, array<string, mixed>> URL → object on the fake cluster */
    private array $objects = [];

    private bool $cilium = false;

    /** @var list<string> "METHOD url" of every write */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);

        Http::fake(function (Request $request) {
            $url = strtok($request->url(), '?');
            $method = $request->method();
            if ($method === 'GET') {
                return match (true) {
                    $url === 'https://k8s.test/api/v1/namespaces/external-secrets' => Http::response(['kind' => 'Namespace']),
                    $url === 'https://k8s.test/apis/cilium.io/v2' => $this->cilium ? Http::response(['kind' => 'APIResourceList']) : Http::response([], 404),
                    $url === 'https://k8s.test/api/v1/namespaces/default/services/kubernetes' => Http::response(['spec' => ['clusterIP' => '10.96.0.1', 'clusterIPs' => ['10.96.0.1'], 'ports' => [['port' => 443]]]]),
                    $url === 'https://k8s.test/api/v1/namespaces/default/endpoints/kubernetes' => Http::response(['subsets' => [['addresses' => [['ip' => '172.18.0.2']], 'ports' => [['port' => 6443]]]]]),
                    isset($this->objects[$url]) => Http::response($this->objects[$url]),
                    default => Http::response(['kind' => 'Status', 'code' => 404], 404),
                };
            }
            $this->writes[] = "{$method} {$url}";
            if ($method === 'DELETE') {
                unset($this->objects[$url]);
            } else {
                $this->objects[$url] = json_decode($request->body(), true);
            }

            return Http::response(['kind' => 'Status'], 200);
        });
    }

    private function group(bool $eso = true, ?string $ownerTenant = null): ClusterGroup
    {
        $region = $this->regionWithCluster('fn');
        if ($eso || $ownerTenant !== null) {
            // owner_tenant_id: a tenant's own region (the settings keep its owner)
            RegionSecretSetting::forRegion($region)->fill([
                'eso_status' => $eso ? RegionSecretSetting::ESO_READY : RegionSecretSetting::ESO_NOT_INSTALLED,
                'owner_tenant_id' => $ownerTenant,
            ])->save();
        }

        return new ClusterGroup('uid:test', [new ReconcileTarget($region->fresh())], new KubernetesClient((string) $region->kubeconfig));
    }

    public function test_the_policy_allows_dns_the_api_server_openbao_and_public_addresses_only(): void
    {
        $policy = ExternalSecretsEgress::networkPolicy('external-secrets', ['addresses' => ['10.96.0.1', '172.18.0.2'], 'ports' => [443, 6443]], ['10.20.0.0/16']);

        $this->assertSame(['app.kubernetes.io/name' => 'external-secrets', 'app.kubernetes.io/instance' => 'external-secrets'], $policy['spec']['podSelector']['matchLabels'], 'the controller only');
        $this->assertSame(['Egress'], $policy['spec']['policyTypes']);
        [$dns, $openbao, $api, $public, $private] = $policy['spec']['egress'];
        $this->assertSame(['k8s-app' => 'kube-dns'], $dns['to'][0]['podSelector']['matchLabels']);
        $this->assertSame(['ipBlock' => ['cidr' => '169.254.20.10/32']], $dns['to'][1], "NodeLocal DNSCache's address, DNS only");
        $this->assertSame([['protocol' => 'UDP', 'port' => 53], ['protocol' => 'TCP', 'port' => 53]], $dns['ports']);
        $this->assertSame('runr-openbao', $openbao['to'][0]['namespaceSelector']['matchLabels']['kubernetes.io/metadata.name']);
        $this->assertSame([['ipBlock' => ['cidr' => '10.96.0.1/32']], ['ipBlock' => ['cidr' => '172.18.0.2/32']]], $api['to']);
        $this->assertSame([['protocol' => 'TCP', 'port' => 443], ['protocol' => 'TCP', 'port' => 6443]], $api['ports']);
        $this->assertSame([['ipBlock' => ['cidr' => '10.20.0.0/16']]], $private['to'], "the platform's private stores");

        $blocks = array_column(array_column($public['to'], 'ipBlock'), 'except', 'cidr');
        $this->assertSame(['0.0.0.0/0', '2000::/3', '64:ff9b::/96'], array_keys($blocks));
        $this->assertSame(OutboundUrlGuard::BLOCKED_IPV4, $blocks['0.0.0.0/0']);
        foreach (['2001::/32', '2001:db8::/32', '3fff::/20', '2002:a9fe::/32', '2002:a00::/24', '2002:7f00::/24', '2002:c0a8::/32', '2002:6440::/26'] as $except) {
            $this->assertContains($except, $blocks['2000::/3']);
        }
        foreach (['64:ff9b::a9fe:0/112', '64:ff9b::a00:0/104', '64:ff9b::7f00:0/104', '64:ff9b::/104'] as $except) {
            $this->assertContains($except, $blocks['64:ff9b::/96']);
        }
    }

    /**
     * What the policy lets through is what the outbound guard counts as public (addresses as they
     * go on the wire: IPv4-mapped and IPv4-compatible forms are not destinations there).
     */
    public function test_the_policy_and_the_guard_agree(): void
    {
        $blocks = ExternalSecretsEgress::publicBlocks();
        $allowed = function (string $ip) use ($blocks): bool {
            foreach ($blocks as $cidr => $except) {
                if (self::in($ip, $cidr)) {
                    foreach ($except as $e) {
                        if (self::in($ip, $e)) {
                            return false;
                        }
                    }

                    return true;
                }
            }

            return false;
        };

        foreach (['8.8.8.8', '1.1.1.1', '100.128.0.1', '203.0.113.10', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1', '192.0.0.170',
            '192.168.1.1', '198.18.0.1', '224.0.0.1', '255.255.255.255', '0.1.2.3',
            '2606:4700:4700::1111', '::1', 'fd00::1', 'fe80::1', 'fec0::1', 'ff02::1', '100::1', '2001::1', '2001:db8::1', '3fff::1',
            '2002:808:808::', '2002:a9fe:a9fe::', '2002:a00:1::', '64:ff9b::808:808', '64:ff9b::a9fe:a9fe', '64:ff9b::a00:1', '64:ff9b:1::1'] as $ip) {
            $this->assertSame(OutboundUrlGuard::isPublicIp($ip), $allowed($ip), $ip);
        }
    }

    private static function in(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = inet_pton($ip);
        $b = inet_pton($net);
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;

        return $rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }

    public function test_reconcile_applies_the_policy_and_repairs_it(): void
    {
        $group = $this->group();
        config(['secrets.external.private_egress_cidrs' => ['10.20.0.0/16', 'not-a-cidr']]);

        $this->assertStringStartsWith('would apply NetworkPolicy external-secrets/runr-eso-egress', (new ExternalSecretsEgressStep)->run($group, true)[0]);
        $this->assertSame([], $this->writes, 'a dry run writes nothing');

        $lines = (new ExternalSecretsEgressStep)->run($group, false);
        $this->assertStringStartsWith('applied NetworkPolicy external-secrets/runr-eso-egress', $lines[0]);
        $applied = $this->objects[self::NP];
        $this->assertEquals(ExternalSecretsEgress::networkPolicy('external-secrets', ['addresses' => ['10.96.0.1', '172.18.0.2'], 'ports' => [443, 6443]], ['10.20.0.0/16']), $applied);

        $this->writes = [];
        $this->assertSame([], (new ExternalSecretsEgressStep)->run($group, false), 'up to date: nothing to do');
        $this->assertSame([], $this->writes);

        // Changed by hand (or the API server moved): repaired
        $this->objects[self::NP]['spec']['egress'][3]['to'] = [['ipBlock' => ['cidr' => '0.0.0.0/0']]];
        $this->assertStringStartsWith('applied', (new ExternalSecretsEgressStep)->run($group, false)[0]);
        $this->assertEquals($applied, $this->objects[self::NP]);
    }

    /**
     * A new or changed policy holds for new connections only (the network plugin lets one it
     * admitted before go on): the controller's Deployment is restarted with it, never when the
     * policy is up to date.
     */
    public function test_a_new_or_changed_policy_restarts_the_controller(): void
    {
        $deployment = 'https://k8s.test/apis/apps/v1/namespaces/external-secrets/deployments/external-secrets';
        $this->objects[$deployment] = ['kind' => 'Deployment', 'metadata' => ['name' => 'external-secrets']];
        $group = $this->group();

        $dry = (new ExternalSecretsEgressStep)->run($group, true);
        $this->assertStringStartsWith('would restart Deployment external-secrets/external-secrets', end($dry));
        $this->assertSame([], $this->writes, 'a dry run writes nothing');

        $lines = (new ExternalSecretsEgressStep)->run($group, false);
        $this->assertStringStartsWith('applied NetworkPolicy external-secrets/runr-eso-egress', $lines[0]);
        $this->assertStringStartsWith('restarted Deployment external-secrets/external-secrets', $lines[1]);
        $this->assertSame('PATCH '.$deployment, end($this->writes), 'after the policy');
        $this->assertNotEmpty($this->objects[$deployment]['spec']['template']['metadata']['annotations'][ExternalSecretsEgress::RESTARTED_AT]);

        // up to date: no restart
        $this->writes = [];
        $this->assertSame([], (new ExternalSecretsEgressStep)->run($group, false));
        $this->assertSame([], $this->writes);

        // changed: applied again, restarted again
        $this->objects[self::NP]['spec']['egress'][3]['to'] = [['ipBlock' => ['cidr' => '0.0.0.0/0']]];
        $lines = (new ExternalSecretsEgressStep)->run($group, false);
        $this->assertCount(2, $lines);
        $this->assertStringEndsWith(self::NP, $this->writes[0]);
        $this->assertSame('PATCH '.$deployment, end($this->writes));
    }

    public function test_on_cilium_a_cilium_policy_replaces_it(): void
    {
        $group = $this->group();
        $this->objects[self::NP] = ExternalSecretsEgress::networkPolicy('external-secrets', ['addresses' => [], 'ports' => [443]], []);
        $this->cilium = true;

        $lines = (new ExternalSecretsEgressStep)->run($group, false);

        $this->assertStringStartsWith('applied CiliumNetworkPolicy external-secrets/runr-eso-egress', $lines[0]);
        $this->assertStringStartsWith('removed NetworkPolicy external-secrets/runr-eso-egress', $lines[1]);
        $policy = $this->objects[self::CNP];
        $this->assertSame(['kube-apiserver'], $policy['spec']['egress'][0]['toEntities'], "Cilium's CIDR rules do not select the cluster's nodes");
        $this->assertSame(['0.0.0.0/0', '2000::/3', '64:ff9b::/96'], array_column($policy['spec']['egress'][4]['toCIDRSet'], 'cidr'));
        $this->assertArrayNotHasKey(self::NP, $this->objects);
    }

    /** @return array<string, array{0: \Closure(self): ClusterGroup}> */
    public static function notWanted(): array
    {
        return [
            'single-tenant' => [function (self $test) {
                config(['tenancy.enabled' => false]);

                return $test->group();
            }],
            "a tenant's own region" => [fn (self $test) => $test->group(true, 'acme')],
            'private targets allowed' => [function (self $test) {
                config(['secrets.external.allow_private_targets' => true]);

                return $test->group();
            }],
            'no operator' => [fn (self $test) => $test->group(false)],
        ];
    }

    /** @param  \Closure(self): ClusterGroup  $group */
    #[DataProvider('notWanted')]
    public function test_the_policy_is_removed_where_it_is_not_wanted(\Closure $group): void
    {
        $this->objects[self::NP] = ExternalSecretsEgress::networkPolicy('external-secrets', ['addresses' => [], 'ports' => [443]], []);

        $lines = (new ExternalSecretsEgressStep)->run($group($this), false);

        $this->assertSame(['removed NetworkPolicy external-secrets/runr-eso-egress (no longer needed)'], $lines);
        $this->assertSame(['DELETE '.self::NP], $this->writes);
    }

    public function test_the_step_is_part_of_region_reconcile_after_the_operator(): void
    {
        $names = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        $this->assertContains('external-secrets-egress', $names);
        $this->assertGreaterThan(array_search('external-secrets', $names, true), array_search('external-secrets-egress', $names, true));
    }

    public function test_embedded_ranges(): void
    {
        $this->assertSame('2002:a9fe::/32', ExternalSecretsEgress::embedded('2002::', 16, '169.254.0.0/16'));
        $this->assertSame('2002:c612::/31', ExternalSecretsEgress::embedded('2002::', 16, '198.18.0.0/15'));
        $this->assertSame('64:ff9b::a00:0/104', ExternalSecretsEgress::embedded('64:ff9b::', 96, '10.0.0.0/8'));
        $this->assertSame('64:ff9b::c000:0/120', ExternalSecretsEgress::embedded('64:ff9b::', 96, '192.0.0.0/24'));
    }
}
