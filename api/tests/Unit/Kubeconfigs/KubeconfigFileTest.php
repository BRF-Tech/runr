<?php

namespace Tests\Unit\Kubeconfigs;

use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Kubeconfigs\KubeconfigFile;
use App\Services\Kubeconfigs\KubeconfigPin;
use App\Services\Kubeconfigs\KubeconfigRefused;
use App\Services\Kubeconfigs\PinProxy;
use App\Services\Sandboxes\Exec\KubectlCommand;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A kubeconfig written to disk for kubectl or helm (KubeconfigFile): a pinned one (an
 * organization's region) is held to the policy again right before it is written and goes through
 * a local proxy (bin/kube-pin-proxy.php) that tunnels to its server's checked addresses only —
 * a redirect of the server to another host, or any other request, is refused there. Any other
 * kubeconfig is written as it is.
 */
class KubeconfigFileTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $cluster  @param  array<string, mixed>  $user */
    private static function kubeconfig(array $cluster, array $user = ['token' => 'abc']): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => $cluster]],
            'users' => [['name' => 'u', 'user' => $user]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /** A TCP server on 127.0.0.1 standing in for the API server's checked address. */
    private function upstream(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $this->sockets[] = $server;
        $name = (string) stream_socket_get_name($server, false);

        return [$server, (int) substr($name, strrpos($name, ':') + 1)];
    }

    /** @return resource */
    private function connect(int $port)
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
        $this->assertNotFalse($client, $errstr);
        stream_set_timeout($client, 5);
        $this->sockets[] = $client;

        return $client;
    }

    /** @param  resource  $socket */
    private static function responseHead($socket): string
    {
        $head = '';
        while (! str_contains($head, "\r\n\r\n") && ($line = fgets($socket)) !== false) {
            $head .= $line;
        }

        return $head;
    }

    public function test_a_platform_kubeconfig_is_written_as_it_is(): void
    {
        $yaml = self::kubeconfig(['server' => 'https://10.0.0.1:6443', 'proxy-url' => 'http://proxy.corp:3128'], ['exec' => ['command' => 'aws']]);
        $file = KubeconfigFile::write($yaml);

        $this->assertFalse($file->pinned());
        $this->assertSame($yaml, file_get_contents($file->path));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file->path)), -4));
        $file->close();
        $this->assertFileDoesNotExist($file->path);
    }

    public function test_a_pinned_kubeconfig_reaches_its_checked_address_through_the_local_proxy_only(): void
    {
        [$upstream, $port] = $this->upstream();
        $pinned = self::kubeconfig(['server' => "https://kube.example.com:{$port}", 'insecure-skip-tls-verify' => true, 'proxy-url' => KubeconfigPin::marker(['127.0.0.1'])]);

        $file = KubeconfigFile::write($pinned, 'runr-test-kc-');
        $this->assertTrue($file->pinned());
        $written = Yaml::parse((string) file_get_contents($file->path));
        $proxyUrl = $written['clusters'][0]['cluster']['proxy-url'];
        $this->assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#', $proxyUrl);
        $this->assertSame("https://kube.example.com:{$port}", $written['clusters'][0]['cluster']['server'], 'the name stays: TLS verifies it');
        $this->assertStringNotContainsString(KubeconfigPin::SCHEME, (string) file_get_contents($file->path));
        $proxyPort = (int) substr($proxyUrl, strrpos($proxyUrl, ':') + 1);

        // a tunnel to the server's host and port goes to the checked address
        $client = $this->connect($proxyPort);
        fwrite($client, "CONNECT kube.example.com:{$port} HTTP/1.1\r\nHost: kube.example.com:{$port}\r\n\r\n");
        $accepted = stream_socket_accept($upstream, 5);
        $this->assertNotFalse($accepted);
        $this->sockets[] = $accepted;
        $this->assertStringStartsWith('HTTP/1.1 200', self::responseHead($client));
        fwrite($client, 'ping');
        $this->assertSame('ping', fread($accepted, 4));
        fwrite($accepted, 'pong');
        $this->assertSame('pong', fread($client, 4));

        // another host (a redirect of the server), another port, a plain request: refused
        foreach ([
            "CONNECT 169.254.169.254:80 HTTP/1.1\r\n\r\n",
            "CONNECT kube.example.com:1 HTTP/1.1\r\n\r\n",
            "GET http://169.254.169.254/latest/meta-data/ HTTP/1.1\r\nHost: 169.254.169.254\r\n\r\n",
        ] as $request) {
            $other = $this->connect($proxyPort);
            fwrite($other, $request);
            $this->assertStringStartsWith('HTTP/1.1 403', self::responseHead($other), $request);
        }

        // closed: the proxy is gone with the file
        $file->close();
        $this->assertFileDoesNotExist($file->path);
        usleep(200000);
        $this->assertFalse(@stream_socket_client("tcp://127.0.0.1:{$proxyPort}", $errno, $errstr, 1));
    }

    public function test_a_pinned_kubeconfig_is_checked_again_before_it_is_written(): void
    {
        $pinned = self::kubeconfig(['server' => 'https://kube.example.com', 'proxy-url' => KubeconfigPin::marker(['203.0.113.10'])], ['exec' => ['command' => '/bin/sh']]);

        try {
            KubeconfigFile::write($pinned);
            $this->fail('refused expected');
        } catch (KubeconfigRefused $e) {
            $this->assertSame('exec', $e->reason);
        }

        $this->expectException(KubeconfigRefused::class);
        KubeconfigFile::write(self::kubeconfig(['server' => 'https://kube.example.com', 'proxy-url' => 'runr-pinned:not-an-address']));
    }

    public function test_helm_and_kubectl_get_the_proxied_file(): void
    {
        $pinned = self::kubeconfig(['server' => 'https://kube.example.com:6443', 'insecure-skip-tls-verify' => true, 'proxy-url' => KubeconfigPin::marker(['203.0.113.10'])]);

        $helm = new class extends HelmService
        {
            /** @var list<string> */
            public array $kubeconfigs = [];

            protected function run(array $command): HelmResult
            {
                $path = $command[array_search('--kubeconfig', $command, true) + 1];
                $this->kubeconfigs[] = (string) file_get_contents($path);

                return new HelmResult(true, '', '', 0);
            }
        };
        $helm->uninstall('rel', 'ns', $pinned);
        $this->assertCount(1, $helm->kubeconfigs);
        $this->assertMatchesRegularExpression('#proxy-url: \'?http://127\.0\.0\.1:\d+#', $helm->kubeconfigs[0]);
        $this->assertStringNotContainsString(KubeconfigPin::SCHEME, $helm->kubeconfigs[0]);

        $path = KubectlCommand::writeKubeconfig($pinned);
        $this->assertMatchesRegularExpression('#proxy-url: \'?http://127\.0\.0\.1:\d+#', (string) file_get_contents($path));
        KubectlCommand::removeKubeconfig($path);
        $this->assertFileDoesNotExist($path);
    }

    public function test_behind_the_operators_proxy_the_tunnel_is_a_connect_to_the_checked_address(): void
    {
        // this test plays the operator's egress proxy (HTTPS_PROXY of the API's environment)
        [$egress, $egressPort] = $this->upstream();
        $saved = getenv('HTTPS_PROXY');
        putenv("HTTPS_PROXY=http://egress:p%40ss@127.0.0.1:{$egressPort}");
        try {
            $proxy = PinProxy::start('kube.example.com', 6443, ['203.0.113.10', '203.0.113.11']);
        } finally {
            putenv($saved === false ? 'HTTPS_PROXY' : "HTTPS_PROXY={$saved}");
        }

        $client = $this->connect($proxy->port);
        fwrite($client, "CONNECT kube.example.com:6443 HTTP/1.1\r\n\r\n");
        $tunnel = stream_socket_accept($egress, 5);
        $this->assertNotFalse($tunnel);
        $this->sockets[] = $tunnel;
        stream_set_timeout($tunnel, 5);
        $request = self::responseHead($tunnel);
        $this->assertStringStartsWith("CONNECT 203.0.113.10:6443 HTTP/1.1\r\n", $request, 'the checked address, never the name');
        $this->assertStringContainsString('Proxy-Authorization: Basic '.base64_encode('egress:p@ss'), $request);
        fwrite($tunnel, "HTTP/1.1 200 Connection established\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 200', self::responseHead($client));
        fwrite($client, 'ping');
        $this->assertSame('ping', fread($tunnel, 4));
        fwrite($tunnel, 'pong');
        $this->assertSame('pong', fread($client, 4));

        // the operator's proxy refuses the first address: the next one, then 502
        $second = $this->connect($proxy->port);
        fwrite($second, "CONNECT kube.example.com:6443 HTTP/1.1\r\n\r\n");
        foreach (['203.0.113.10', '203.0.113.11'] as $address) {
            $attempt = stream_socket_accept($egress, 5);
            $this->assertNotFalse($attempt);
            $this->sockets[] = $attempt;
            stream_set_timeout($attempt, 5);
            $this->assertStringStartsWith("CONNECT {$address}:6443 ", self::responseHead($attempt));
            fwrite($attempt, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n");
        }
        $this->assertStringStartsWith('HTTP/1.1 502', self::responseHead($second));
        $proxy->stop();
    }

    public function test_the_proxy_takes_only_valid_targets(): void
    {
        $proxy = PinProxy::start('2001:db8::1', 6443, ['2001:db8::1']);
        $this->assertGreaterThan(0, $proxy->port);
        $proxy->stop();

        $this->expectException(\RuntimeException::class);
        PinProxy::start('kube.example.com', 6443, ['not-an-address']);
    }
}
