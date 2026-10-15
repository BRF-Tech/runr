<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\CacheVersionService;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResponseCache
{
    private const PREFIX = 'runr:rc:';

    private const TTL = 60;

    public function __construct(
        private CacheVersionService $cacheVersion
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        // Skip cache when a module-injected context header is present
        // (e.g. module-scoped cache tags make cross-context version bumps invisible).
        if ($request->header('X-Context-ID')) {
            return $next($request);
        }

        // An explicit refresh (?refresh=1, e.g. GET /knative-regions/{id}/gpu reading the cluster)
        // is never answered from — nor stored in — the cache
        if ($request->boolean('refresh')) {
            return $next($request);
        }

        $scopes = $this->resolveScopes($request);
        if (empty($scopes)) {
            return $next($request);
        }

        // Bodies depend on who asks (roles, project access, API token scope): only a signed-in
        // request is answered from the cache, with what was cached for the same user and token.
        // The middleware priority list runs this after authentication and route authorization;
        // resolving the user through the Sanctum guard keeps an anonymous request uncached even
        // if that order changes.
        $user = $this->authenticatedUser();
        if (! $user) {
            return $next($request);
        }

        $cacheKey = $this->buildCacheKey($request, $scopes, $user);

        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response($cached, 200)
                    ->header('Content-Type', 'application/json')
                    ->header('X-Cache', 'HIT');
            }
        } catch (\Throwable) {
            // Cache unavailable — skip
        }

        $response = $next($request);

        // A response that opted out of caching (secret values, ProjectSecretController) stays out
        if ($response->getStatusCode() === 200 && ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $this->storeInCache($cacheKey, $response->getContent());
        }

        $response->headers->set('X-Cache', 'MISS');

        return $response;
    }

    private function authenticatedUser(): ?Authenticatable
    {
        try {
            return Auth::guard('sanctum')->user();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveScopes(Request $request): array
    {
        $path = $request->path();

        // Secret values and their metadata are never cached (ProjectSecretController)
        if (str_contains($path, '/secrets')) {
            return [];
        }

        // Project ids are ULIDs: the scope carries the whole id — the one CacheInvalidationObserver
        // bumps (CacheVersionService::projectScope). A digits-only match took the ULID's leading
        // digits, so every project shared one scope that no change ever bumped.
        if (preg_match('#^api/projects/([^/]+)#', $path, $m)) {
            $key = $this->projectKey($request, $m[1]);

            return $key === null ? ['projects'] : ['projects', CacheVersionService::projectScope($key)];
        }
        if ($path === 'api/projects') {
            return ['projects'];
        }
        if (str_starts_with($path, 'api/settings') || str_starts_with($path, 'api/knative-regions')) {
            return ['settings'];
        }

        return [];
    }

    /**
     * The key of the route's project: the bound model (a project resolved by another route key
     * still gets its ULID scope), else the path segment when it is an id (alphanumeric).
     */
    private function projectKey(Request $request, string $segment): ?string
    {
        $project = $request->route('project');
        if ($project instanceof Project) {
            return (string) $project->getKey();
        }

        return preg_match('/^[0-9A-Za-z]+$/', $segment) ? $segment : null;
    }

    private function buildCacheKey(Request $request, array $scopes, Authenticatable $user): string
    {
        $path = $request->path();
        $query = $request->getQueryString();
        $versionTag = $this->cacheVersion->versionTag($scopes);

        $key = self::PREFIX.$path;
        if ($query) {
            $key .= '?'.$query;
        }

        // Include context ID to prevent cross-context cache collisions
        $contextId = $request->header('X-Context-ID', 'default');
        $key .= ':c'.$contextId;
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $key .= ':u'.$user->getAuthIdentifier()
            .':t'.($token instanceof PersonalAccessToken ? $token->getKey() : '-');

        return $key.':v'.$versionTag;
    }

    private function storeInCache(string $key, string|false $content): void
    {
        if ($content === false || $content === '') {
            return;
        }

        try {
            Cache::put($key, $content, self::TTL);
        } catch (\Throwable) {
            // Cache unavailable — skip
        }
    }
}
