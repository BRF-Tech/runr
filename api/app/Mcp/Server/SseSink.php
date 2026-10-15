<?php

namespace App\Mcp\Server;

use App\Mcp\Protocol\JsonRpc;

/**
 * The SSE stream of one POST /mcp answered with text/event-stream: notifications of the running
 * request (progress, log messages), then its response — after which the server closes the
 * stream. Every event is `event: message` with one JSON-RPC message as data.
 */
final class SseSink implements MessageSink
{
    private int $sequence = 0;

    private float $lastWrite;

    /** @var \Closure(string): void */
    private \Closure $writer;

    public function __construct(private readonly string $streamId, ?\Closure $writer = null)
    {
        $this->lastWrite = microtime(true);
        $this->writer = $writer ?? static function (string $chunk): void {
            echo $chunk;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };
    }

    public function notify(string $method, array|object $params): void
    {
        $this->send(JsonRpc::notification($method, $params));
    }

    /** One JSON-RPC message as an SSE event. */
    public function send(array $message): void
    {
        $this->write('id: '.$this->streamId.'-'.(++$this->sequence)."\nevent: message\ndata: ".JsonRpc::encode($message)."\n\n");
    }

    public function keepAlive(): void
    {
        if (microtime(true) - $this->lastWrite >= 15) {
            $this->write(": keepalive\n\n");
        }
    }

    public function streaming(): bool
    {
        return true;
    }

    private function write(string $chunk): void
    {
        ($this->writer)($chunk);
        $this->lastWrite = microtime(true);
    }
}
