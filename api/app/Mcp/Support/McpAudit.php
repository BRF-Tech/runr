<?php

namespace App\Mcp\Support;

use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Audit events of the MCP surface that do not map to one API route (AuditCatalog::EXPLICIT_ACTIONS):
 * sessions, every tools/call, OAuth registrations and token issue / revocation, hosted server calls.
 * The actor of a call over /mcp is the token (auth method mcp:<token id>); arguments are recorded
 * by name only, never their values.
 */
final class McpAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** auth_method of a request authenticated with $user's current API token. */
    public static function authMethod(?Authenticatable $user): string
    {
        $token = $user && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $id = $token instanceof PersonalAccessToken ? $token->getKey() : null;

        return $id !== null ? 'mcp:'.$id : 'mcp';
    }

    public function sessionStarted(Request $request, Authenticatable $user, array $clientInfo, string $protocolVersion): void
    {
        $this->audit->record('mcp.session.start', [
            'request' => $request,
            'actor' => $user,
            'auth_method' => self::authMethod($user),
            'target' => ['type' => 'mcp_session', 'name' => self::clientName($clientInfo)],
            'metadata' => [
                'client' => self::clientName($clientInfo),
                'client_version' => isset($clientInfo['version']) ? mb_substr((string) $clientInfo['version'], 0, 100) : null,
                'protocol_version' => $protocolVersion,
            ],
        ]);
    }

    public function sessionEnded(Request $request, Authenticatable $user, ?string $clientName): void
    {
        $this->audit->record('mcp.session.end', [
            'request' => $request,
            'actor' => $user,
            'auth_method' => self::authMethod($user),
            'target' => ['type' => 'mcp_session', 'name' => $clientName],
        ]);
    }

    /**
     * @param  list<string>  $argumentNames
     */
    public function toolCall(Request $request, Authenticatable $user, string $tool, array $argumentNames, string $result, int $durationMs, ?array $target = null, ?string $error = null, ?string $client = null): void
    {
        $this->audit->record('mcp.tool.call', [
            'request' => $request,
            'actor' => $user,
            'auth_method' => self::authMethod($user),
            'result' => $result,
            'target' => $target ?: ['type' => 'mcp_tool', 'name' => $tool],
            'metadata' => array_filter([
                'tool' => $tool,
                'arguments' => array_values(array_map('strval', $argumentNames)),
                'duration_ms' => $durationMs,
                'error' => $error !== null ? mb_substr($error, 0, 300) : null,
                'client' => $client,
            ], fn ($v) => $v !== null && $v !== []),
        ]);
    }

    public function clientRegistered(Request $request, string $clientId, string $name, array $redirectHosts, string $authMethod): void
    {
        $this->audit->record('oauth.client.register', [
            'request' => $request,
            'target' => ['type' => 'oauth_client', 'id' => $clientId, 'name' => $name],
            'metadata' => ['redirect_hosts' => $redirectHosts, 'token_endpoint_auth_method' => $authMethod],
        ]);
    }

    public function tokenIssued(Request $request, Authenticatable $user, string $clientId, string $clientName, int|string $tokenId, array $abilities, ?string $expiresAt, string $resource, ?string $tenantId): void
    {
        $this->audit->record('oauth.token.issue', [
            'request' => $request,
            'actor' => $user,
            'auth_method' => 'oauth:'.mb_substr($clientId, 0, 30),
            'tenant_id' => $tenantId,
            'target' => ['type' => 'oauth_client', 'id' => $clientId, 'name' => $clientName],
            'metadata' => ['token_id' => (string) $tokenId, 'abilities' => $abilities, 'expires_at' => $expiresAt, 'resource' => $resource],
        ]);
    }

    public function tokenRefused(Request $request, string $clientId, string $reason): void
    {
        $this->audit->record('oauth.token.issue', [
            'request' => $request,
            'result' => AuditEvent::RESULT_FAILURE,
            'target' => ['type' => 'oauth_client', 'id' => $clientId],
            'metadata' => ['reason' => $reason],
        ]);
    }

    public function tokenRevoked(Request $request, Authenticatable $user, string $clientId, int|string $tokenId, ?string $tenantId): void
    {
        $this->audit->record('oauth.token.revoke', [
            'request' => $request,
            'actor' => $user,
            'auth_method' => 'oauth:'.mb_substr($clientId, 0, 30),
            'tenant_id' => $tenantId,
            'target' => ['type' => 'oauth_client', 'id' => $clientId],
            'metadata' => ['token_id' => (string) $tokenId],
        ]);
    }

    /** A call through the hosted-server gateway (only when the server's audit_calls is on). */
    public function hostedCall(Request $request, ?Authenticatable $user, ?string $tenantId, array $project, string $method, ?string $name, string $result, int $status, string $auth): void
    {
        $this->audit->record('mcp.server.call', [
            'request' => $request,
            'actor' => $user ?? ['actor_type' => AuditEvent::ACTOR_ANONYMOUS, 'actor_id' => null, 'actor_name' => null, 'actor_email' => null, 'auth_method' => 'public'],
            'auth_method' => $user ? 'mcp_gateway:'.$auth : null,
            'tenant_id' => $tenantId,
            'result' => $result,
            'status_code' => $status,
            'target' => ['type' => 'project', 'id' => $project['id'] ?? null, 'name' => $project['name'] ?? null],
            'metadata' => array_filter(['method' => $method, 'name' => $name, 'auth' => $auth], fn ($v) => $v !== null),
        ]);
    }

    private static function clientName(array $clientInfo): ?string
    {
        $name = $clientInfo['title'] ?? $clientInfo['name'] ?? null;

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 255) : null;
    }
}
