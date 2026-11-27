<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Hosting\GatewayForwarder;
use App\Services\Observability\OutboundTarget;
use App\Workflows\Engine\EndpointResolver;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The forward to a checked address (GatewayForwarder::forwardPinned()), with a real socket: a name
 * that cannot resolve (.invalid) reaches the pinned address — the stream opens the literal
 * address and resolves nothing — with the name as its Host header; an SSE answer stays a stream.
 */
class GatewayForwarderPinTest extends TestCase
{
    private ?Process $server = null;

    private string $docroot = '';

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        if ($this->docroot !== '' && is_dir($this->docroot)) {
            @unlink($this->docroot.'/router.php');
            @rmdir($this->docroot);
        }
        parent::tearDown();
    }

    /** PHP's built-in server on 127.0.0.1 answering with the request it got; its port. */
    private function startServer(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $this->docroot = sys_get_temp_dir().'/runr-mcp-pin-'.bin2hex(random_bytes(4));
        mkdir($this->docroot);
        file_put_contents($this->docroot.'/router.php', <<<'PHP'
<?php
$sse = str_contains($_SERVER['REQUEST_URI'], 'sse');
header('Content-Type: '.($sse ? 'text/event-stream' : 'application/json'));
header('Mcp-Session-Id: s-1');
echo json_encode([
    'host' => $_SERVER['HTTP_HOST'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'identity' => $_SERVER['HTTP_X_RUNR_IDENTITY'] ?? null,
    'body' => file_get_contents('php://input'),
]);
PHP);

        $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $this->docroot.'/router.php']);
        $this->server->start();
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket) {
                fclose($socket);

                return $port;
            }
            usleep(50_000);
        }
        $this->fail('the test server did not start: '.$this->server->getErrorOutput());
    }

    public function test_the_forward_goes_to_the_pinned_address_with_the_name_as_host(): void
    {
        $port = $this->startServer();
        $forwarder = new GatewayForwarder(app(EndpointResolver::class));
        $target = OutboundTarget::pinned('weather.op.runr-pin-test.invalid', $port, ['127.0.0.1']);

        $result = $forwarder->forwardPinned($target, 'POST', "http://weather.op.runr-pin-test.invalid:{$port}/mcp?x=1", '{"jsonrpc":"2.0"}',
            ['Content-Type' => 'application/json', 'X-RunR-Identity' => 'id-1'], 10, (int) hrtime(true));

        $this->assertTrue($result->reachable(), (string) $result->error);
        $this->assertSame(200, $result->status);
        $this->assertSame('s-1', $result->headers['Mcp-Session-Id']);
        $echo = json_decode((string) $result->body, true);
        $this->assertSame("weather.op.runr-pin-test.invalid:{$port}", $echo['host']);
        $this->assertSame(['POST', '/mcp?x=1', 'id-1', '{"jsonrpc":"2.0"}'], [$echo['method'], $echo['uri'], $echo['identity'], $echo['body']]);

        $sse = $forwarder->forwardPinned($target, 'GET', "http://weather.op.runr-pin-test.invalid:{$port}/sse", '', [], 10, (int) hrtime(true));
        $this->assertTrue($sse->isStream());
        $this->assertStringContainsString('"uri":"\/sse"', (string) $sse->stream?->getContents());
    }
}
