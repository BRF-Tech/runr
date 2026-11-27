<?php

namespace Tests\Unit\Kubeconfigs;

use App\Models\KnativeRegion;
use App\Services\Dns\DnsResolver;
use App\Services\Kubeconfigs\KubeconfigPin;
use App\Services\Kubeconfigs\KubeconfigRefused;
use App\Services\Kubeconfigs\TenantKubeconfigs;
use App\Services\KubernetesClient;
use App\Services\Sandboxes\Exec\ExecKubeconfig;
use App\Services\Sandboxes\Exec\NativeExecUnsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The server of an organization's region kubeconfig (TenantKubeconfigs): checked like any
 * outbound URL (https, public addresses only; private ones with the operator's flag, never
 * loopback or link-local) and pinned to the addresses checked — RunR's client resolves through
 * them, follows no redirect and no proxy, the native exec client dials the first, and a refused
 * kubeconfig of a stored region reaches nothing. Platform regions keep theirs as they are.
 */
class TenantKubeconfigsTest extends TestCase
{
    /** @var DnsResolver&object{answers: array<string, list<string>>, lookups: list<string>} */
    private DnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new class extends DnsResolver
        {
            /** @var array<string, list<string>> */
            public array $answers = [];

            /** @var list<string> */
            public array $lookups = [];

            public function addresses(string $name): array
            {
                $this->lookups[] = $name;

                return $this->answers[rtrim($name, '.')] ?? [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);
    }

    /** A fresh service: the check of a kubeconfig is remembered per instance */
    private function service(): TenantKubeconfigs
    {
        $this->app->forgetInstance(TenantKubeconfigs::class);

        return app(TenantKubeconfigs::class);
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $cluster  more fields of the cluster
     */
    private static function kubeconfig(string $server, array $user = ['token' => 'abc'], array $cluster = []): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'insecure-skip-tls-verify' => true] + $cluster]],
            'users' => [['name' => 'u', 'user' => $user]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /** @return array<string, mixed> */
    private static function cluster(string $yaml): array
    {
        return Yaml::parse($yaml)['clusters'][0]['cluster'];
    }

    private function refused(string $kubeconfig, string $reason = 'server_blocked'): KubeconfigRefused
    {
        try {
            $this->service()->forUse($kubeconfig);
        } catch (KubeconfigRefused $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }
        $this->fail('refused expected');
    }

    public function test_a_public_server_is_pinned_to_the_addresses_it_resolved_to(): void
    {
        $this->dns->answers['api.example.com'] = ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];

        $yaml = $this->service()->forUse(self::kubeconfig('https://api.example.com:6443'));

        $cluster = self::cluster($yaml);
        $this->assertSame('https://api.example.com:6443', $cluster['server'], 'the name stays: TLS verifies it');
        $this->assertSame(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], KubeconfigPin::addresses($cluster));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function blockedServers(): array
    {
        return [
            'a private address' => ['https://api.example.com', ['10.0.0.5']],
            'one private among public ones (rebinding)' => ['https://api.example.com', ['93.184.216.34', '127.0.0.1']],
            'cloud metadata' => ['https://169.254.169.254', []],
            'the API server of RunR\'s own cluster' => ['https://kubernetes.default.svc', []],
            'loopback' => ['https://127.0.0.1:6443', []],
            'IPv6 loopback' => ['https://[::1]:6443', []],
            'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]:6443', []],
            'a name that does not resolve' => ['https://nowhere.example.com', []],
        ];
    }

    /** @param  list<string>  $addresses */
    #[DataProvider('blockedServers')]
    public function test_a_server_outside_the_public_addresses_is_refused(string $server, array $addresses): void
    {
        $this->dns->answers['api.example.com'] = $addresses;

        $e = $this->refused(self::kubeconfig($server));

        foreach ($addresses as $ip) {
            $this->assertStringNotContainsString($ip, $e->getMessage(), 'no address the name resolved to');
        }
    }

    public function test_the_operators_flag_accepts_private_cluster_addresses_but_never_loopback_or_link_local(): void
    {
        config(['tenancy.region_kubeconfig.allow_private_servers' => true]);
        $this->dns->answers['k8s.corp.example'] = ['10.20.0.5'];
        $this->dns->answers['bad.corp.example'] = ['10.20.0.5', '169.254.169.254'];

        $this->assertSame(['10.20.0.5'], KubeconfigPin::addresses(self::cluster($this->service()->forUse(self::kubeconfig('https://k8s.corp.example:6443')))));
        $this->assertSame(['192.168.1.10'], KubeconfigPin::addresses(self::cluster($this->service()->forUse(self::kubeconfig('https://192.168.1.10:6443')))));
        $this->refused(self::kubeconfig('https://bad.corp.example'));
        $this->refused(self::kubeconfig('https://127.0.0.1'));
        $this->refused(self::kubeconfig('https://[::ffff:169.254.169.254]'));
        $this->refused(self::kubeconfig('https://0.0.0.0'));

        $this->assertTrue(TenantKubeconfigs::mayReach('10.0.0.5'));
        $this->assertFalse(TenantKubeconfigs::mayReach('127.0.0.1'));
        config(['tenancy.region_kubeconfig.allow_private_servers' => false]);
        $this->assertFalse(TenantKubeconfigs::mayReach('10.0.0.5'));
        $this->assertTrue(TenantKubeconfigs::mayReach('93.184.216.34'));
    }

    public function test_a_literal_public_address_is_pinned_to_itself_and_policy_refusals_come_first(): void
    {
        $this->assertSame(['203.0.113.10'], KubeconfigPin::addresses(self::cluster($this->service()->forUse(self::kubeconfig('https://203.0.113.10:6443')))));
        $this->assertSame(['2001:4860:4860::8888'], KubeconfigPin::addresses(self::cluster($this->service()->forUse(self::kubeconfig('https://[2001:4860:4860::8888]')))));

        $this->refused(self::kubeconfig('https://203.0.113.10', ['exec' => ['command' => 'sh']]), 'exec');
        $this->refused(self::kubeconfig('http://203.0.113.10'), 'server');
        $this->assertSame([], $this->dns->lookups, 'nothing resolved for a literal address or a refused kubeconfig');
    }

    public function test_the_check_is_remembered_and_a_pinned_kubeconfig_is_checked_again_as_a_new_one(): void
    {
        $this->dns->answers['api.example.com'] = ['93.184.216.34'];
        $service = $this->service();
        $pinned = $service->forUse(self::kubeconfig('https://api.example.com'));
        $service->forUse(self::kubeconfig('https://api.example.com'));
        $this->assertCount(1, $this->dns->lookups);

        // the pinned copy read back (e.g. from a cache) is a kubeconfig of its own: checked anew
        $this->dns->answers['api.example.com'] = ['10.0.0.9'];
        $this->refused($pinned);
    }

    public function test_which_regions_are_restricted(): void
    {
        $this->assertFalse(TenantKubeconfigs::restricts(new KnativeRegion));
        $this->assertTrue(TenantKubeconfigs::restricts((new KnativeRegion)->forceFill(['owner_tenant_id' => 'acme'])));
        $this->assertTrue(TenantKubeconfigs::restricts((new KnativeRegion)->forceFill(['is_synced' => false])));
        $this->assertFalse(TenantKubeconfigs::restricts((new KnativeRegion)->forceFill(['is_synced' => true])), 'a copy of a platform region');
        $this->assertFalse(TenantKubeconfigs::restrictsNewRegions(), 'no tenant context');
    }

    public function test_the_region_hands_out_a_pinned_kubeconfig_or_none(): void
    {
        $this->dns->answers['api.example.com'] = ['93.184.216.34'];
        $exec = self::kubeconfig('https://api.example.com', ['exec' => ['command' => '/bin/sh', 'apiVersion' => 'client.authentication.k8s.io/v1']]);

        // an organization's region stored by an earlier release with a credential plugin
        $own = (new KnativeRegion)->forceFill(['owner_tenant_id' => 'acme', 'kubeconfig' => $exec]);
        $this->assertNull($own->kubeconfig, 'nothing connects with it');
        $this->assertSame($exec, $own->storedKubeconfig(), 'what its owner gave, to replace');
        $this->assertStringContainsString('users[0].user.exec', (string) app(TenantKubeconfigs::class)->refusal($own));
        $this->assertNotNull($own->server_url);

        // an organization's region with an accepted kubeconfig: pinned on every read
        $fine = (new KnativeRegion)->forceFill(['owner_tenant_id' => 'acme', 'kubeconfig' => self::kubeconfig('https://api.example.com')]);
        $this->assertSame(['93.184.216.34'], KubeconfigPin::addresses(self::cluster((string) $fine->kubeconfig)));
        $this->assertNull(app(TenantKubeconfigs::class)->refusal($fine));

        // a platform region: as it is, exec plugin included
        $platform = (new KnativeRegion)->forceFill(['kubeconfig' => $exec]);
        $this->assertSame($exec, $platform->kubeconfig);
        $this->assertNull(app(TenantKubeconfigs::class)->refusal($platform));

        // a demo region of an organization
        $demo = (new KnativeRegion)->forceFill(['owner_tenant_id' => 'acme', 'kubeconfig' => 'demo', 'is_demo' => true]);
        $this->assertSame('demo', $demo->kubeconfig);
    }

    public function test_the_old_cluster_kubeconfig_of_a_move_is_held_to_the_same_checks(): void
    {
        $exec = self::kubeconfig('https://203.0.113.10', ['tokenFile' => '/var/run/secrets/kubernetes.io/serviceaccount/token']);
        $own = (new KnativeRegion)->forceFill(['owner_tenant_id' => 'acme', 'migration_old_kubeconfig' => $exec]);

        $this->assertNull($own->migration_old_kubeconfig);
        $this->assertSame($exec, $own->storedMigrationOldKubeconfig());
        $this->assertSame($exec, (new KnativeRegion)->forceFill(['migration_old_kubeconfig' => $exec])->migration_old_kubeconfig);
    }

    public function test_runrs_client_connects_to_the_pinned_addresses_only(): void
    {
        $this->dns->answers['api.example.com'] = ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];
        $client = new KubernetesClient($this->service()->forUse(self::kubeconfig('https://api.example.com:6443')));

        $options = $this->clientOptions($client);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy'], 'no proxy of the environment: it would resolve the name itself');
        $this->assertSame([
            'api.example.com:6443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]',
            'api.example.com.:6443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]',
        ], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('https://api.example.com:6443', $client->server());

        // a literal address: nothing to resolve, still no redirect and no proxy
        $literal = $this->clientOptions(new KubernetesClient($this->service()->forUse(self::kubeconfig('https://203.0.113.10:6443'))));
        $this->assertFalse($literal['allow_redirects']);
        $this->assertArrayNotHasKey('curl', $literal);

        // a platform kubeconfig: as before
        $platform = $this->clientOptions(new KubernetesClient(self::kubeconfig('https://api.example.com:6443')));
        $this->assertArrayNotHasKey('allow_redirects', $platform);
        $this->assertArrayNotHasKey('curl', $platform);
    }

    public function test_runrs_client_refuses_no_kubeconfig_and_a_damaged_pin(): void
    {
        try {
            new KubernetesClient(null);
            $this->fail('no kubeconfig: refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('regions.kubeconfig.none'), $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        new KubernetesClient(self::kubeconfig('https://api.example.com', cluster: ['proxy-url' => 'runr-pinned:not-an-address']));
    }

    public function test_the_native_exec_client_dials_the_first_pinned_address_and_verifies_the_name(): void
    {
        $this->dns->answers['api.example.com'] = ['93.184.216.34'];
        $config = ExecKubeconfig::parse($this->service()->forUse(self::kubeconfig('https://api.example.com:6443')));

        $this->assertSame('tls://93.184.216.34:6443', $config->address());
        $this->assertSame('api.example.com', $config->sslOptions()['peer_name']);
        $this->assertSame('api.example.com:6443', $config->hostHeader());
        $this->assertNull($config->environmentProxy([]), 'straight to the checked address');
        $this->assertSame('HTTPS_PROXY', $config->environmentProxy(['HTTPS_PROXY' => 'http://proxy.example.com:3128']), 'behind a proxy: kubectl, through the pin proxy chained to it');
        $this->assertNull($config->environmentProxy(['HTTPS_PROXY' => 'http://proxy.example.com:3128', 'NO_PROXY' => '.example.com']));

        // a proxy-url that is no pin stays kubectl's
        $this->expectException(NativeExecUnsupported::class);
        ExecKubeconfig::parse(self::kubeconfig('https://api.example.com', cluster: ['proxy-url' => 'http://10.0.0.1:3128']));
    }

    /** @return array<string, mixed> the HTTP options of the client's transport */
    private function clientOptions(KubernetesClient $client): array
    {
        return (fn () => $this->transport()['options'])->call($client);
    }
}
