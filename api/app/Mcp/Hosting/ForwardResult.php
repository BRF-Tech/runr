<?php

namespace App\Mcp\Hosting;

use Psr\Http\Message\StreamInterface;

/** The answer of a hosted MCP server to one forwarded request (GatewayForwarder). */
final class ForwardResult
{
    /**
     * @param  array<string, string>  $headers  headers to return to the client (GatewayForwarder::RETURNED)
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly ?StreamInterface $stream,
        public readonly int $durationMs,
        public readonly ?string $error = null,
    ) {}

    public static function unreachable(string $error, int $durationMs): self
    {
        return new self(502, ['Content-Type' => 'application/json'], null, null, $durationMs, $error);
    }

    public function reachable(): bool
    {
        return $this->error === null;
    }

    public function isStream(): bool
    {
        return $this->stream !== null;
    }
}
