<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ETagResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->method() !== 'GET' || $response->getStatusCode() !== 200) {
            return $response;
        }

        // Responses with secrets (ProjectSecretController) stay out of every cache
        if (str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return $response;
        }

        $etag = '"'.md5($content).'"';

        // A response a controller marked as publicly cacheable (the public status page:
        // "public, max-age=30") keeps its directive; everything else is revalidated each time
        $cacheControl = $response->headers->hasCacheControlDirective('public')
            ? (string) $response->headers->get('Cache-Control')
            : 'no-cache';

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', $cacheControl);

        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        return $response;
    }
}
