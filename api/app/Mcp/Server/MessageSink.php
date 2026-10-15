<?php

namespace App\Mcp\Server;

/**
 * Where server → client messages of a running request go: the SSE stream of a streamed
 * response (SseSink), or nowhere when the request is answered with plain JSON (NullSink).
 */
interface MessageSink
{
    /** Sends a JSON-RPC notification (notifications/progress, notifications/message). */
    public function notify(string $method, array|object $params): void;

    /** Keeps an idle stream open through proxies (an SSE comment). */
    public function keepAlive(): void;

    /** Whether notifications reach the client at all. */
    public function streaming(): bool;
}
