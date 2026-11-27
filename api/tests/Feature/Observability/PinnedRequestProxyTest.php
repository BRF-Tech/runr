<?php

namespace Tests\Feature\Observability;

use App\Mcp\Hosting\GatewayForwarder;
use App\Models\RuntimeSource;
use App\Services\Dns\DnsResolver;
use App\Services\Kubeconfigs\KubeconfigPin;
use App\Services\Kubeconfigs\PinProxy;
use App\Services\KubernetesClient;
use App\Services\Observability\OutboundUrlGuard;
use App\Services\Runtimes\Sources\GitSourceReader;
use App\Services\Sandboxes\Exec\ExecKubeconfig;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A pinned request behind the operator's egress proxy (HTTPS_PROXY / http_proxy, NO_PROXY): it
 * goes through the proxy as a CONNECT to the address the guard checked — never to the name, so
 * the proxy cannot resolve it again — and TLS still verifies the name. Without a proxy for the
 * host it goes straight to the checked addresses. The paths: OutboundTarget::options() (alert
 * channels, uptime, replay, workflows, billing, OIDC / GitLab, registries, transfer buckets),
 * RunR's Kubernetes client, the MCP gateway's pinned stream, the git reader (through a PinProxy);
 * the native exec client never uses a proxy and leaves a proxied server to kubectl.
 */
class PinnedRequestProxyTest extends TestCase
{
    private const VARIABLES = ['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'NO_PROXY', 'no_proxy', 'ALL_PROXY', 'all_proxy'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function addresses(string $name): array
            {
                return match (rtrim($name, '.')) {
                    'hooks.example.com' => ['2606:4700:4700::1111', '93.184.216.34'],
                    'git.acme.test', 'plain.example.com' => ['93.184.216.35'],
                    default => [],
                };
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        parent::tearDown();
    }

    /** @param  array<string, string>  $variables */
    private function environment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            putenv("{$name}={$value}");
        }
    }

    public function test_a_guarded_request_goes_through_the_proxy_as_a_connect_to_the_checked_address(): void
    {
        $this->environment(['HTTPS_PROXY' => 'http://egress:s3cret@proxy.corp:3128', 'NO_PROXY' => 'internal.corp']);

        $this->assertSame([
            'allow_redirects' => false,
            'proxy' => 'http://egress:s3cret@proxy.corp:3128',
            'curl' => [
                CURLOPT_CONNECT_TO => [
                    'hooks.example.com:443:[2606:4700:4700::1111]:443',
                    'hooks.example.com.:443:[2606:4700:4700::1111]:443',
                ],
                CURLOPT_HTTPPROXYTUNNEL => true,
            ],
        ], app(OutboundUrlGuard::class)->target('https://hooks.example.com/services/x')->options());

        // an http:// URL takes http_proxy, and is tunnelled too: the proxy gets no name to resolve
        $this->environment(['http_proxy' => 'http://plain-proxy.corp:8080']);
        $plain = app(OutboundUrlGuard::class)->target('http://plain.example.com:8080/hook')->options();
        $this->assertSame('http://plain-proxy.corp:8080', $plain['proxy']);
        $this->assertSame(['plain.example.com:8080:93.184.216.35:8080', 'plain.example.com.:8080:93.184.216.35:8080'], $plain['curl'][CURLOPT_CONNECT_TO]);
        $this->assertTrue($plain['curl'][CURLOPT_HTTPPROXYTUNNEL]);
    }

    public function test_without_a_proxy_for_the_host_the_request_goes_straight_to_the_checked_addresses(): void
    {
        foreach ([
            ['HTTPS_PROXY' => 'http://proxy.corp:3128', 'NO_PROXY' => '.example.com'],
            ['HTTPS_PROXY' => 'socks5h://proxy.corp:1080'],
            ['ALL_PROXY' => 'http://proxy.corp:3128'],
        ] as $variables) {
            foreach (self::VARIABLES as $name) {
                putenv($name);
            }
            $this->environment($variables);

            $options = app(OutboundUrlGuard::class)->target('https://hooks.example.com/x')->options();
            $this->assertSame('', $options['proxy'], json_encode($variables).': no proxy of curl\'s own either');
            $this->assertSame('hooks.example.com:443:[2606:4700:4700::1111],93.184.216.34', $options['curl'][CURLOPT_RESOLVE][0]);
            $this->assertArrayNotHasKey(CURLOPT_CONNECT_TO, $options['curl']);
        }
    }

    public function test_runrs_kubernetes_client_goes_through_the_proxy_to_the_pinned_address(): void
    {
        $this->environment(['HTTPS_PROXY' => 'http://proxy.corp:3128']);
        $kubeconfig = fn (string $server) => Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'proxy-url' => KubeconfigPin::marker(['93.184.216.34', '93.184.216.35'])]]],
            'users' => [['name' => 'u', 'user' => ['token' => 't']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
        $options = fn (KubernetesClient $client) => (fn () => $this->transport()['options'])->call($client);

        $named = $options(new KubernetesClient($kubeconfig('https://kube.acme.example:6443')));
        $this->assertSame('http://proxy.corp:3128', $named['proxy']);
        $this->assertSame('kube.acme.example:6443:93.184.216.34:6443', $named['curl'][CURLOPT_CONNECT_TO][0]);
        $this->assertFalse($named['allow_redirects']);

        $literal = $options(new KubernetesClient($kubeconfig('https://203.0.113.10:6443')));
        $this->assertSame('http://proxy.corp:3128', $literal['proxy'], 'CONNECT 203.0.113.10:6443: nothing to resolve');
        $this->assertArrayNotHasKey('curl', $literal);
    }

    public function test_the_native_exec_client_leaves_a_proxied_server_to_kubectl(): void
    {
        $config = ExecKubeconfig::parse(Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://kube.acme.example:6443', 'insecure-skip-tls-verify' => true, 'proxy-url' => KubeconfigPin::marker(['93.184.216.34'])]]],
            'users' => [['name' => 'u', 'user' => ['token' => 't']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2));

        $this->assertSame('tls://93.184.216.34:6443', $config->address(), 'the native client dials the checked address, never a proxy');
        $this->assertSame('HTTPS_PROXY', $config->environmentProxy(['HTTPS_PROXY' => 'http://proxy.corp:3128']));
        $this->assertNull($config->environmentProxy([]));
    }

    public function test_the_mcp_gateways_pinned_stream_tunnels_through_an_http_proxy(): void
    {
        $headers = ['Host: weather.acme.example'];
        $this->assertSame(['proxy' => 'tcp://proxy.corp:3128', 'request_fulluri' => false], GatewayForwarder::proxyContext('http://egress:p%40ss@proxy.corp:3128', 'https', $headers));
        $this->assertSame('Proxy-Authorization: Basic '.base64_encode('egress:p@ss'), $headers[1]);
        $this->assertSame(['proxy' => 'tcp://proxy.corp:80', 'request_fulluri' => true], GatewayForwarder::proxyContext('http://proxy.corp', 'http', $headers));

        $none = ['Host: x'];
        $this->assertSame([], GatewayForwarder::proxyContext(null, 'https', $none));
        $this->assertSame([], GatewayForwarder::proxyContext('https://proxy.corp:3129', 'https', $none), 'PHP\'s stream speaks to an http:// proxy only: straight to the address');
        $this->assertSame(['Host: x'], $none);
    }

    public function test_the_git_reader_goes_through_a_pin_proxy_behind_the_operators_proxy(): void
    {
        $this->environment(['HTTPS_PROXY' => 'http://proxy.corp:3128']);
        $reader = app(GitSourceReader::class);
        $access = fn (string $url) => (new \ReflectionMethod(GitSourceReader::class, 'access'))
            ->invoke($reader, new RuntimeSource(['kind' => 'git', 'scope' => RuntimeSource::SCOPE_ORGANIZATION, 'url' => $url]), sys_get_temp_dir());

        [$env, $config] = $access('https://git.acme.test/acme/runtimes.git');
        $proxy = (fn () => $this->pinProxy)->call($reader);
        try {
            $this->assertInstanceOf(PinProxy::class, $proxy);
            $this->assertSame('http://127.0.0.1:'.$proxy->port, $config['http.proxy']);
            $this->assertArrayNotHasKey('http.curloptResolve', $config, 'the pin proxy holds the checked address');
            $this->assertSame('false', $config['http.followRedirects']);
            $this->assertSame(['', '', ''], [$env['HTTPS_PROXY'], $env['https_proxy'], $env['NO_PROXY']], 'git uses the pin proxy only');
        } finally {
            $proxy?->stop();
        }

        // SSH has no way through an HTTP proxy: straight to the checked address, as before
        [$env] = $access('ssh://git@git.acme.test/acme/runtimes.git');
        $this->assertStringContainsString("'HostName=93.184.216.35'", $env['GIT_SSH_COMMAND']);
        $this->assertStringContainsString("'ProxyCommand=none'", $env['GIT_SSH_COMMAND']);
    }
}
