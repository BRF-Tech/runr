<?php

namespace App\Mcp\Hosting;

use App\Mcp\Models\HostedServer;
use App\Mcp\Support\McpAuth;
use App\Mcp\Support\McpUrls;
use App\Models\Project;
use App\Support\Auth\LoginTokens;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentication of a call to a hosted MCP server (docs/mcp.md, "Access modes"):
 *
 *   public  nobody is asked; the server sees auth "public"
 *   token   a RunR API token (Bearer) whose user holds mcp.servers.call on the project — limited by
 *           the token's own permissions; a token the OAuth server issued must be issued for this
 *           server (audience)
 *   oauth   the same, and a 401 names the protected resource metadata, so MCP clients connect
 *           through the RunR OAuth server with one click
 *
 * The token is checked like Sanctum checks it (expiry, the owner's account), before the tenant of
 * the server is entered; the permission check runs inside it.
 */
final class GatewayAuthenticator
{
    public function __construct(private readonly Rbac $rbac) {}

    /**
     * The caller's identity, or the 401 answer.
     *
     * @return array{0: ?GatewayIdentity, 1: ?JsonResponse}
     */
    public function authenticate(Request $request, HostedServer $server): array
    {
        if (! $server->requiresToken()) {
            return [GatewayIdentity::anonymous($server->tenant_id), null];
        }

        $advertise = $server->access_mode === HostedServer::OAUTH;
        $plain = $request->bearerToken();
        $token = is_string($plain) && $plain !== '' ? PersonalAccessToken::findToken($plain) : null;

        if (! $token || ! $this->valid($token)) {
            return [null, McpAuth::challenge($request, $server->handle, $plain ? 'invalid_token' : null, $advertise)];
        }

        // A token of another organization does not open this one's servers
        $pinned = $token->getAttribute('tenant_id');
        if ($pinned !== null && $server->tenant_id !== null && (string) $pinned !== (string) $server->tenant_id) {
            return [null, McpAuth::challenge($request, $server->handle, 'invalid_token', $advertise)];
        }

        // A token the OAuth server issued for another resource
        $audience = $token->getAttribute('oauth_resource');
        if (is_string($audience) && $audience !== '' && McpUrls::resourceHandle($audience, $request) !== $server->handle) {
            return [null, McpAuth::challenge($request, $server->handle, 'invalid_token', $advertise)];
        }

        /** @var Authenticatable&HasApiTokens $user */
        $user = $token->tokenable;
        $user->withAccessToken($token);
        $token->forceFill(['last_used_at' => now()])->save();

        $auth = $token->getAttribute('oauth_client_id') ? GatewayIdentity::AUTH_OAUTH : GatewayIdentity::AUTH_TOKEN;

        return [new GatewayIdentity($auth, $user, $server->tenant_id, $token), null];
    }

    /** Inside the server's tenant: may the caller call the project's server? 403 answer when not. */
    public function authorize(Request $request, HostedServer $server, Project $project, GatewayIdentity $identity): ?JsonResponse
    {
        if (! $server->requiresToken() || $identity->user === null) {
            return null;
        }

        $this->rbac->flush();
        if ($this->rbac->allows($identity->user, 'mcp.servers.call', $project)) {
            return null;
        }

        return new JsonResponse([
            'error' => 'insufficient_scope',
            'error_description' => 'Calling this MCP server requires the permission mcp.servers.call on its project.',
        ], 403, [
            'WWW-Authenticate' => 'Bearer realm="mcp", error="insufficient_scope", scope="mcp.servers.call"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Sanctum's checks (global expiration, expires_at, the sign-in session lifetime), an existing
     * and enabled owner.
     */
    private function valid(PersonalAccessToken $token): bool
    {
        if (LoginTokens::expired($token)) {
            return false;
        }

        $user = $token->tokenable;

        return $user instanceof Authenticatable && ! ($user->is_disabled ?? false);
    }
}
