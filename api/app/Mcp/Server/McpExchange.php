<?php

namespace App\Mcp\Server;

use App\Mcp\Models\McpSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One HTTP exchange with the RunR MCP server: the caller, its session, the negotiated protocol
 * version and where notifications go. initialize fills in the new session.
 */
final class McpExchange
{
    public ?string $newSessionId = null;

    public function __construct(
        public readonly Request $request,
        public readonly Authenticatable $user,
        public ?McpSession $session,
        public string $protocolVersion,
        public MessageSink $sink = new NullSink,
    ) {}

    public function tokenId(): ?int
    {
        $token = method_exists($this->user, 'currentAccessToken') ? $this->user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }

    public function logLevel(): string
    {
        return $this->session?->log_level ?: 'info';
    }

    public function clientName(): ?string
    {
        return $this->session?->client_name;
    }
}
