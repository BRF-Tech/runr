<?php

namespace App\Mcp\Http\Middleware;

use App\Mcp\Support\McpUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Origin check and CORS of the MCP surface — /mcp, /mcp/servers/*, /oauth2/* and the
 * /.well-known/oauth-* metadata (global middleware; the `api` group's CORS covers api/* only):
 *
 *  - no Origin header (CLI, desktop and server-side clients): passes
 *  - the console's own origin and config mcp.allowed_origins (wildcards: http://localhost:*):
 *    passes, with CORS headers; preflights are answered here
 *  - any other browser origin: 403 — Streamable HTTP servers must validate Origin (DNS rebinding)
 *
 * Bearer tokens only, never cookies: no credentials mode.
 */
class McpCors
{
    private const EXPOSED = 'Mcp-Session-Id, WWW-Authenticate, Retry-After';

    private const ALLOWED = 'Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID, X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return $next($request);
        }

        if (! $this->allowed($origin, $request)) {
            return response()->json(['error' => 'forbidden_origin', 'error_description' => 'This origin may not call the MCP endpoints.'], 403);
        }

        $response = $request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')
            ? response('', 204)->withHeaders([
                'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => self::ALLOWED,
                'Access-Control-Max-Age' => '600',
            ])
            : $next($request);

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSED);
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Origin', ', '));

        return $response;
    }

    public static function applies(Request $request): bool
    {
        return $request->is('mcp', 'mcp/*', 'oauth2/*', '.well-known/oauth-*');
    }

    private function allowed(string $origin, Request $request): bool
    {
        if (rtrim($origin, '/') === McpUrls::base($request)) {
            return true;
        }

        foreach ((array) config('mcp.allowed_origins', []) as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '*') {
                return true;
            }
            $regex = '#^'.str_replace('\*', '[^/]*', preg_quote(rtrim($pattern, '/'), '#')).'$#i';
            if ($pattern !== '' && preg_match($regex, rtrim($origin, '/'))) {
                return true;
            }
        }

        return false;
    }
}
