<?php

namespace App\Mcp\Server;

/** A request answered with application/json: notifications have no channel and are dropped. */
final class NullSink implements MessageSink
{
    public function notify(string $method, array|object $params): void {}

    public function keepAlive(): void {}

    public function streaming(): bool
    {
        return false;
    }
}
