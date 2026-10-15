<?php

namespace App\Services\Shell;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Laravel\Reverb\Servers\Reverb\Http\Connection;
use Laravel\Reverb\Servers\Reverb\Http\Router;
use Psr\Http\Message\RequestInterface;

class SafeRouter extends Router
{
    /**
     * Fix vendor bug: operator precedence in isWebSocketRequest().
     * Reverb v1.8 has: `$arr[0] ?? null === 'websocket'` which parses as
     * `$arr[0] ?? (null === 'websocket')` instead of `($arr[0] ?? null) === 'websocket'`.
     */
    protected function isWebSocketRequest(RequestInterface $request): bool
    {
        return ($request->getHeader('Upgrade')[0] ?? null) === 'websocket';
    }

    /**
     * Dispatch route — catch type errors for non-WS requests hitting WS-only routes.
     */
    public function dispatch(RequestInterface $request, Connection $connection): mixed
    {
        try {
            return parent::dispatch($request, $connection);
        } catch (\TypeError) {
            $response = new Response(400, ['Content-Type' => 'text/plain'], 'WebSocket upgrade required');
            $connection->send(Message::toString($response))->close();

            return null;
        }
    }
}
