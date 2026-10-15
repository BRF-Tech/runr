<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks responses `Cache-Control: no-store, private`: ResponseCache and ETagResponse leave them
 * alone and no proxy keeps a copy. For live state polled by the console and bodies with secret
 * values (routes/git-deploy.php: Git tab, deploy history, previews).
 */
class NoStoreResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
