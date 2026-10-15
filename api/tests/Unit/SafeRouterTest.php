<?php

namespace Tests\Unit;

use App\Services\Shell\SafeRouter;
use GuzzleHttp\Psr7\Request;
use Laravel\Reverb\Servers\Reverb\Connection as WebSocketConnection;
use Laravel\Reverb\Servers\Reverb\Http\Connection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use React\Socket\ConnectionInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A plain HTTP request on a WebSocket-only route (shell, agent) is answered with a 400
 * instead of crashing the Reverb server.
 */
class SafeRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_plain_http_request_on_a_websocket_route_gets_400(): void
    {
        $routes = new RouteCollection;
        $routes->add('shell', new Route('/shell', [
            '_controller' => fn (RequestInterface $request, WebSocketConnection $connection) => null,
        ]));
        $router = new SafeRouter(new UrlMatcher($routes, new RequestContext));

        $written = '';
        $socket = Mockery::mock(ConnectionInterface::class);
        $socket->stream = 1; // Reverb reads the connection id from the socket's stream
        $socket->shouldReceive('write')->andReturnUsing(function (string $data) use (&$written) {
            $written .= $data;

            return true;
        });
        $socket->shouldReceive('end')->once();

        $result = $router->dispatch(new Request('GET', 'http://localhost/shell'), new Connection($socket));

        $this->assertNull($result);
        $this->assertStringStartsWith('HTTP/1.1 400', $written);
        $this->assertStringContainsString('WebSocket upgrade required', $written);
    }
}
