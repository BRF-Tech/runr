<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\Helpers\AgentWsUrlResolver;
use App\Services\Helpers\RunrClusterIdentity;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AgentWsUrlTest extends TestCase
{
    private string $saDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saDir = sys_get_temp_dir().'/runr-sa-'.uniqid();
        config([
            'knative.agent.serviceaccount_dir' => $this->saDir,
            'knative.agent.ws_url' => null,
            'knative.agent.runr_namespace' => null,
            'app.url' => 'https://runr.example.com',
        ]);
        putenv('KUBERNETES_SERVICE_HOST');
        putenv('KUBERNETES_SERVICE_PORT');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (['token', 'namespace', 'ca.crt'] as $f) {
            @unlink("{$this->saDir}/{$f}");
        }
        @rmdir($this->saDir);

        parent::tearDown();
    }

    // ── URL resolution order ────────────────────────────────────────────────

    public function test_region_setting_wins(): void
    {
        config(['knative.agent.ws_url' => 'ws://global.example']);
        $region = $this->region(['agent_ws_url' => 'https://runr.internal/']);

        $this->assertSame(
            "wss://runr.internal/ws/agent/{$region->id}",
            $this->resolver(inCluster: true)->resolve($region),
        );
    }

    public function test_same_cluster_uses_the_in_cluster_gateway(): void
    {
        config(['knative.agent.ws_url' => 'ws://global.example']);
        $region = $this->region();

        $this->assertSame(
            "ws://runr-gateway.runr.svc.cluster.local/ws/agent/{$region->id}",
            $this->resolver(inCluster: true)->resolve($region),
        );
    }

    public function test_gateway_service_port_and_cluster_domain_are_configurable(): void
    {
        config([
            'knative.agent.gateway_service' => 'gw',
            'knative.agent.gateway_port' => 8080,
            'knative.agent.cluster_domain' => 'k8s.local',
        ]);
        $region = $this->region();

        $this->assertSame(
            "ws://gw.runr.svc.k8s.local:8080/ws/agent/{$region->id}",
            $this->resolver(inCluster: true)->resolve($region),
        );
    }

    public function test_service_link_env_does_not_break_the_in_cluster_url(): void
    {
        // kubelet service links: Service runr-gateway → RUNR_GATEWAY_PORT=tcp://<ip>:80 in every pod
        $link = 'tcp://10.43.38.102:80';
        putenv("RUNR_GATEWAY_PORT={$link}");
        $_ENV['RUNR_GATEWAY_PORT'] = $_SERVER['RUNR_GATEWAY_PORT'] = $link;
        try {
            $agent = (require config_path('knative.php'))['agent'];
            $this->assertSame(80, $agent['gateway_port']);
            $this->assertSame('runr-gateway', $agent['gateway_service']);
        } finally {
            putenv('RUNR_GATEWAY_PORT');
            unset($_ENV['RUNR_GATEWAY_PORT'], $_SERVER['RUNR_GATEWAY_PORT']);
        }

        config(['knative.agent.gateway_port' => 0]);
        $region = $this->region();

        $this->assertSame(
            "ws://runr-gateway.runr.svc.cluster.local/ws/agent/{$region->id}",
            $this->resolver(inCluster: true)->resolve($region),
        );
    }

    public function test_other_cluster_uses_the_global_override(): void
    {
        config(['knative.agent.ws_url' => 'http://10.0.0.10:8080']);
        $region = $this->region();

        $this->assertSame(
            "ws://10.0.0.10:8080/ws/agent/{$region->id}",
            $this->resolver(inCluster: false)->resolve($region),
        );
    }

    public function test_falls_back_to_app_url(): void
    {
        $region = $this->region();

        $this->assertSame(
            "wss://runr.example.com/ws/agent/{$region->id}",
            $this->resolver(inCluster: false)->resolve($region),
        );
    }

    public function test_legacy_region_app_url_setting_is_still_honoured(): void
    {
        $region = $this->region(['app_url' => 'http://legacy.example/']);

        $this->assertSame(
            "ws://legacy.example/ws/agent/{$region->id}",
            $this->resolver(inCluster: false)->resolve($region),
        );
    }

    public function test_to_ws_base_normalises_urls(): void
    {
        $this->assertSame('wss://a.example', AgentWsUrlResolver::toWsBase(' https://a.example/ '));
        $this->assertSame('ws://a.example', AgentWsUrlResolver::toWsBase('ws://a.example/ws/agent/01abc'));
        $this->assertSame('ws://a.example:81', AgentWsUrlResolver::toWsBase('HTTP://a.example:81'));
    }

    // ── Same-cluster detection ──────────────────────────────────────────────

    public function test_identity_matches_on_kube_system_uid(): void
    {
        $this->writeServiceAccount();
        Http::fake([
            'https://kubernetes.default.svc/api/v1/namespaces/kube-system' => Http::response(['metadata' => ['uid' => 'uid-1']]),
            'https://region.test:6443/api/v1/namespaces/kube-system' => Http::response(['metadata' => ['uid' => 'uid-1']]),
        ]);

        $identity = new RunrClusterIdentity;

        $this->assertTrue($identity->isRunrCluster($this->region()));
        $this->assertSame('runr', $identity->namespace());
        Http::assertSent(fn ($r) => $r->url() === 'https://kubernetes.default.svc/api/v1/namespaces/kube-system'
            && $r->header('Authorization') === ['Bearer sa-token']);
    }

    public function test_identity_differs_on_another_cluster(): void
    {
        $this->writeServiceAccount();
        Http::fake([
            'https://kubernetes.default.svc/*' => Http::response(['metadata' => ['uid' => 'uid-1']]),
            'https://region.test:6443/*' => Http::response(['metadata' => ['uid' => 'uid-2']]),
        ]);

        $this->assertFalse((new RunrClusterIdentity)->isRunrCluster($this->region()));
    }

    public function test_identity_is_false_outside_a_pod(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['uid' => 'uid-1']])]);

        $this->assertFalse((new RunrClusterIdentity)->isRunrCluster($this->region()));
        $this->assertNull((new RunrClusterIdentity)->namespace());
    }

    public function test_identity_is_false_when_the_region_api_refuses(): void
    {
        $this->writeServiceAccount();
        Http::fake([
            'https://kubernetes.default.svc/*' => Http::response(['metadata' => ['uid' => 'uid-1']]),
            'https://region.test:6443/*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $this->assertFalse((new RunrClusterIdentity)->isRunrCluster($this->region()));
    }

    public function test_identity_is_false_when_the_api_is_unreachable(): void
    {
        $this->writeServiceAccount();
        Http::fake(fn () => throw new \RuntimeException('unreachable'));

        $this->assertFalse((new RunrClusterIdentity)->isRunrCluster($this->region()));
    }

    public function test_namespace_config_overrides_the_service_account(): void
    {
        $this->writeServiceAccount();
        config(['knative.agent.runr_namespace' => 'custom']);

        $this->assertSame('custom', (new RunrClusterIdentity)->namespace());
    }

    public function test_in_cluster_server_comes_from_the_service_env(): void
    {
        $this->writeServiceAccount();
        putenv('KUBERNETES_SERVICE_HOST=10.43.0.1');
        putenv('KUBERNETES_SERVICE_PORT=443');
        Http::fake([
            // PSR-7 drops the default port from the request URL
            'https://10.43.0.1/*' => Http::response(['metadata' => ['uid' => 'uid-1']]),
            'https://region.test:6443/*' => Http::response(['metadata' => ['uid' => 'uid-1']]),
        ]);

        try {
            $this->assertTrue((new RunrClusterIdentity)->isRunrCluster($this->region()));
        } finally {
            putenv('KUBERNETES_SERVICE_HOST');
            putenv('KUBERNETES_SERVICE_PORT');
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function resolver(bool $inCluster): AgentWsUrlResolver
    {
        $identity = new class($inCluster) extends RunrClusterIdentity
        {
            public function __construct(private bool $same) {}

            public function isRunrCluster(KnativeRegion $region): bool
            {
                return $this->same;
            }

            public function namespace(): ?string
            {
                return 'runr';
            }
        };

        return new AgentWsUrlResolver($identity);
    }

    private function region(array|string $settings = []): KnativeRegion
    {
        $name = is_string($settings) ? $settings : 'op';

        $region = new KnativeRegion([
            'name' => $name,
            'kubeconfig' => $this->kubeconfig($name),
            'knative_settings' => is_array($settings) ? $settings : [],
        ]);
        $region->id = '01test'.str_pad($name, 20, '0');

        return $region;
    }

    private function kubeconfig(string $marker): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => "admin-{$marker}"]]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function writeServiceAccount(): void
    {
        @mkdir($this->saDir, 0700, true);
        file_put_contents("{$this->saDir}/token", "sa-token\n");
        file_put_contents("{$this->saDir}/namespace", 'runr');
    }
}
