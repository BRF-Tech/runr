<?php

namespace App\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mcp\Models\HostedServer;
use App\Mcp\OAuth\AuthorizationServer;
use App\Mcp\OAuth\OAuthException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The OAuth 2.1 endpoints MCP clients use (docs/mcp.md, "OAuth"): metadata, registration,
 * authorization, token, revocation. Public routes outside the `api` group; the consent itself is
 * OAuthConsentController (signed-in console API).
 */
class OAuthController extends Controller
{
    public function __construct(private readonly AuthorizationServer $server) {}

    /** GET /.well-known/oauth-authorization-server */
    public function authorizationServerMetadata(Request $request): JsonResponse
    {
        abort_unless(config('mcp.oauth.enabled', true), 404);

        return $this->metadata($this->server->metadata($request));
    }

    /** GET /.well-known/oauth-protected-resource[/mcp] */
    public function protectedResourceMetadata(Request $request): JsonResponse
    {
        return $this->metadata($this->server->resourceMetadata($request));
    }

    /** GET /.well-known/oauth-protected-resource/mcp/servers/{handle} */
    public function hostedResourceMetadata(Request $request, string $handle): JsonResponse
    {
        $server = HostedServer::forHandle($handle);
        abort_unless($server && $server->requiresToken(), 404);

        return $this->metadata($this->server->resourceMetadata($request, $server->handle));
    }

    /** POST /oauth2/register */
    public function register(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->server->register($request), 201, ['Cache-Control' => 'no-store']);
        } catch (OAuthException $e) {
            return $e->toResponse();
        }
    }

    /** GET /oauth2/authorize — the browser goes on to the consent page (or back with an error). */
    public function authorization(Request $request): RedirectResponse
    {
        return redirect()->away($this->server->authorize($request));
    }

    /** POST /oauth2/token */
    public function token(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->server->token($request), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthException $e) {
            return $e->toResponse();
        }
    }

    /** POST /oauth2/revoke */
    public function revoke(Request $request): JsonResponse
    {
        try {
            $this->server->revoke($request);
        } catch (OAuthException $e) {
            return $e->toResponse();
        }

        return new JsonResponse(new \stdClass, 200, ['Cache-Control' => 'no-store']);
    }

    private function metadata(array $document): JsonResponse
    {
        return new JsonResponse($document, 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES);
    }
}
