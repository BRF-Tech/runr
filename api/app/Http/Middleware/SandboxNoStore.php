<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sandbox API responses carry command output, file contents and sandbox state: `no-store`
 * keeps them out of every cache — the API's own ResponseCache and ETag handling skip such
 * responses, and so do proxies and browsers.
 */
class SandboxNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $streaming = str_starts_with((string) $response->headers->get('Content-Type'), 'text/event-stream');
        $response->headers->set('Cache-Control', $streaming ? 'no-store, no-cache, no-transform, private' : 'no-store, private');

        return $response;
    }
}
