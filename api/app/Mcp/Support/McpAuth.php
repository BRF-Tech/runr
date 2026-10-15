<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Bearer authentication of the MCP surface (MCP authorization, RFC 6750 / RFC 9728):
 *
 *  - a request without a valid token gets 401 with
 *    WWW-Authenticate: Bearer resource_metadata="<protected resource metadata URL>", scope="…"
 *  - a token the OAuth server issued for another resource (audience, RFC 8707) is refused with
 *    error="invalid_token" — a token for a hosted MCP server does not open /mcp, and the other
 *    way round. Personal API tokens carry no audience and work on both.
 */
final class McpAuth
{
    /** Scopes a client is asked for when it has none in mind (the consent screen can add more). */
    public const DEFAULT_SCOPES = ['projects.view', 'logs.view', 'metrics.view', 'regions.view'];

    /**
     * The 401 answer. $handle: '' for the RunR MCP server, a hosted server's handle otherwise;
     * $advertise false: no OAuth discovery (hosted servers in token mode).
     */
    public static function challenge(Request $request, string $handle = '', ?string $error = null, bool $advertise = true): JsonResponse
    {
        $parts = ['realm="mcp"'];
        if ($advertise && config('mcp.oauth.enabled', true)) {
            $parts[] = 'resource_metadata="'.($handle === '' ? McpUrls::resourceMetadata($request) : McpUrls::hostedResourceMetadata($handle, $request)).'"';
            $parts[] = 'scope="'.implode(' ', $handle === '' ? self::DEFAULT_SCOPES : ['mcp.servers.call']).'"';
        }
        if ($error !== null) {
            $parts[] = 'error="'.$error.'"';
        }

        return new JsonResponse([
            'error' => $error ?? 'unauthorized',
            'error_description' => $error === 'invalid_token'
                ? 'The access token is not valid for this resource.'
                : 'Authentication required: send a RunR API token as a Bearer token, or connect through OAuth.',
        ], 401, [
            'WWW-Authenticate' => 'Bearer '.implode(', ', $parts),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The audience of the caller's token: null (personal API token) or a resource URL. */
    public static function audience(?Authenticatable $user): ?string
    {
        $token = $user && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $resource = $token->getAttribute('oauth_resource');

        return is_string($resource) && $resource !== '' ? $resource : null;
    }

    /** 401 when the token was issued for another resource than $handle ('' = /mcp); null when fine. */
    public static function refuseForeignAudience(Request $request, ?Authenticatable $user, string $handle): ?JsonResponse
    {
        $audience = self::audience($user);
        if ($audience === null) {
            return null;
        }

        return McpUrls::resourceHandle($audience, $request) === $handle
            ? null
            : self::challenge($request, $handle, 'invalid_token');
    }
}
