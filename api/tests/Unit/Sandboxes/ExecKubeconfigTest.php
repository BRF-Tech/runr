<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\Exec\ExecKubeconfig;
use App\Services\Sandboxes\Exec\NativeExecUnsupported;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The kubeconfig forms of the native exec client (docs/sandbox-api.md): which credentials it
 * takes itself, which only kubectl handles, and when a proxy of the environment sends a server's
 * commands to kubectl — the NO_PROXY rules kubectl follows.
 */
class ExecKubeconfigTest extends TestCase
{
    protected function tearDown(): void
    {
        ExecKubeconfig::flush();
        parent::tearDown();
    }

    private static function kubeconfig(string $server, array $user = [], array $cluster = []): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'k', 'user' => 'u']]],
            'clusters' => [['name' => 'k', 'cluster' => ['server' => $server] + $cluster]],
            'users' => [['name' => 'u', 'user' => $user === [] ? (object) [] : $user]],
        ], 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }

    public function test_the_forms_it_takes_itself(): void
    {
        $ca = base64_encode("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");

        // a ServiceAccount token (kubectl create token; RunR's in-cluster kubeconfig has the same shape)
        $sa = ExecKubeconfig::parse(self::kubeconfig('https://10.96.0.1:443', ['token' => 'eyJ.sa'], ['certificate-authority-data' => $ca]));
        $this->assertSame(['https', '10.96.0.1', 443, 'eyJ.sa'], [$sa->scheme, $sa->host, $sa->port, $sa->token]);

        // client certificate and key as data, alone or with a token
        $cert = ExecKubeconfig::parse(self::kubeconfig('https://k8s.example:6443', ['client-certificate-data' => base64_encode('cert'), 'client-key-data' => base64_encode('key')]));
        $this->assertNull($cert->token);
        $both = ExecKubeconfig::parse(self::kubeconfig('https://k8s.example:6443', ['token' => 't', 'client-certificate-data' => base64_encode('cert'), 'client-key-data' => base64_encode('key')]));
        $this->assertSame('t', $both->token);

        // nothing at all (an anonymous API server), insecure TLS, a path prefix (Rancher)
        $plain = ExecKubeconfig::parse(self::kubeconfig('https://rancher.example/k8s/clusters/c-1', [], ['insecure-skip-tls-verify' => true]));
        $this->assertSame(['/k8s/clusters/c-1', true, null], [$plain->pathPrefix, $plain->insecure, $plain->token]);
    }

    public function test_the_forms_only_kubectl_handles_say_so(): void
    {
        foreach (ExecKubeconfig::UNSUPPORTED_USER as $field => $what) {
            $value = $field === 'exec' || $field === 'auth-provider' || $field === 'as-groups' || $field === 'as-user-extra' ? ['x' => 'y'] : 'x';
            try {
                ExecKubeconfig::parse(self::kubeconfig('https://k8s.example:6443', [$field => $value]));
                $this->fail("{$field}: the native client must not take it");
            } catch (NativeExecUnsupported $e) {
                $this->assertSame("the kubeconfig user uses {$what} ({$field}), which only kubectl handles", $e->getMessage());
                $this->assertFalse($e->remember, 'a form of one kubeconfig, not of the server');
            }
        }
        foreach (ExecKubeconfig::UNSUPPORTED_CLUSTER as $field => $what) {
            try {
                ExecKubeconfig::parse(self::kubeconfig('https://k8s.example:6443', ['token' => 't'], [$field => 'x']));
                $this->fail("{$field}: the native client must not take it");
            } catch (NativeExecUnsupported $e) {
                $this->assertSame("the kubeconfig cluster uses {$what} ({$field}), which only kubectl handles", $e->getMessage());
            }
        }

        $this->expectExceptionMessage('a client certificate without its key');
        ExecKubeconfig::parse(self::kubeconfig('https://k8s.example:6443', ['client-certificate-data' => base64_encode('cert')]));
    }

    public function test_a_proxy_of_the_environment_and_no_proxy(): void
    {
        $https = ExecKubeconfig::parse(self::kubeconfig('https://api.k8s.example.com:6443', ['token' => 't']));
        $ip = ExecKubeconfig::parse(self::kubeconfig('https://10.20.30.40:6443', ['token' => 't']));
        $v6 = ExecKubeconfig::parse(self::kubeconfig('https://[fd00::10]:6443', ['token' => 't']));
        $http = ExecKubeconfig::parse(self::kubeconfig('http://api.k8s.example.com:8080', ['token' => 't']));
        $proxy = ['HTTPS_PROXY' => 'http://proxy:3128'];

        $this->assertNull($https->environmentProxy([]));
        $this->assertSame('HTTPS_PROXY', $https->environmentProxy($proxy));
        $this->assertSame('https_proxy', $https->environmentProxy(['https_proxy' => 'http://proxy:3128']));
        $this->assertNull($https->environmentProxy(['HTTPS_PROXY' => '  ']), 'an empty value is no proxy');
        // an http server follows HTTP_PROXY only
        $this->assertNull($http->environmentProxy($proxy));
        $this->assertSame('HTTP_PROXY', $http->environmentProxy(['HTTP_PROXY' => 'http://proxy:3128']));
        $this->assertSame('http_proxy', $http->environmentProxy(['http_proxy' => 'http://proxy:3128']));

        // NO_PROXY: [server, entries, still through the proxy?]
        $cases = [
            [$https, '*', false],
            [$https, 'k8s.example.com', false],          // the domain and its subdomains
            [$https, 'api.k8s.example.com', false],
            [$https, '.k8s.example.com', false],         // subdomains only
            [$https, '*.k8s.example.com', false],
            [$https, '.api.k8s.example.com', true],      // not the host itself
            [$https, 'example.org, other.example.com', true],
            [$https, 'xample.com', true],                // a suffix that is no domain
            [$https, 'K8S.Example.COM', false],           // case does not matter
            [$https, 'api.k8s.example.com:6443', false], // with the server's port
            [$https, 'api.k8s.example.com:443', true],   // another port
            [$ip, '10.20.30.40', false],
            [$ip, '10.20.30.41', true],
            [$ip, '10.0.0.0/8', false],
            [$ip, '10.20.30.0/25', false],
            [$ip, '10.20.30.128/25', true],
            [$ip, '10.20.30.40:6443', false],
            [$ip, '10.20.30.40:443', true],
            [$ip, '10.20.30.0/33', true],                // not a CIDR
            [$v6, 'fd00::10', false],
            [$v6, '[fd00::10]:6443', false],
            [$v6, '[fd00::10]:443', true],
            [$v6, 'fd00::/8', false],
            [$v6, 'fe80::/10', true],
            [$v6, '10.0.0.0/8', true],
            [$https, ' , ,', true],
        ];
        foreach ($cases as [$config, $noProxy, $proxied]) {
            $this->assertSame($proxied ? 'HTTPS_PROXY' : null, $config->environmentProxy($proxy + ['NO_PROXY' => $noProxy]), "{$config->host} with NO_PROXY={$noProxy}");
        }
        $this->assertNull($https->environmentProxy($proxy + ['no_proxy' => 'example.com']), 'no_proxy as well');

        // localhost and loopback addresses never go through a proxy
        foreach (['https://localhost:6443', 'https://127.0.0.1:6443', 'https://127.4.5.6:6443', 'https://[::1]:6443'] as $server) {
            $this->assertNull(ExecKubeconfig::parse(self::kubeconfig($server, ['token' => 't']))->environmentProxy($proxy), $server);
        }
    }
}
