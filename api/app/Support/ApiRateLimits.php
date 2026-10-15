<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named rate limiters for the HTTP API (see config/ratelimit.php).
 *
 * `api`  — applied to the whole `api` middleware group. Throttle runs after authentication and
 *          after tenant identification (middleware priority), so the tenant bucket is keyed by
 *          the tenant the request was *resolved* to (membership already checked) — a raw
 *          X-Tenant-ID header on an unauthenticated call never selects a tenant bucket.
 * `auth` — login / register / password / invitation accept / OAuth + SAML sign-in, per IP + login.
 *
 * Over the limit: HTTP 429, JSON body, Retry-After + X-RateLimit-* headers.
 */
class ApiRateLimits
{
    public static function register(): void
    {
        RateLimiter::for('api', fn (Request $request) => static::apiLimits($request));
        RateLimiter::for('auth', fn (Request $request) => static::authLimits($request));
    }

    /** @return Limit|Limit[] */
    public static function apiLimits(Request $request): Limit|array
    {
        if (! config('ratelimit.enabled', true) || static::isExcluded($request)) {
            return Limit::none();
        }

        // An API call an MCP tool makes (App\Mcp\Server\InternalApi): the MCP request that
        // triggered it already counted against this limiter
        if (($request->attributes->get('mcp')['internal'] ?? false) === true) {
            return Limit::none();
        }

        $limits = [];

        if ($tenant = static::tenantKey($request)) {
            $limits[] = Limit::perMinute((int) config('ratelimit.api.per_tenant_per_minute', 1200))
                ->by('tenant:'.$tenant)
                ->response(static::tooManyRequests(...));
        }

        $principal = static::principalKey($request);
        $perMinute = str_starts_with($principal, 'ip:')
            ? (int) config('ratelimit.api.per_guest_per_minute', 120)
            : (int) config('ratelimit.api.per_user_per_minute', 300);

        $limits[] = Limit::perMinute($perMinute)
            ->by($principal)
            ->response(static::tooManyRequests(...));

        return $limits;
    }

    public static function authLimits(Request $request): Limit
    {
        if (! config('ratelimit.enabled', true)) {
            return Limit::none();
        }

        return Limit::perMinute((int) config('ratelimit.auth.per_minute', 10))
            ->by(static::authKey($request))
            ->response(static::tooManyRequests(...));
    }

    public static function isExcluded(Request $request): bool
    {
        $patterns = array_values(array_filter((array) config('ratelimit.except', [])));

        return $patterns !== [] && $request->is(...$patterns);
    }

    /** Tenant the request was resolved to (IdentifyTenant), or null outside a tenant context. */
    public static function tenantKey(Request $request): ?string
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant && function_exists('tenancy') && config('tenancy.enabled')) {
            $tenant = tenancy()->initialized ? tenancy()->tenant : null;
        }

        if (! $tenant) {
            return null;
        }

        $key = is_object($tenant) && method_exists($tenant, 'getTenantKey')
            ? $tenant->getTenantKey()
            : ($tenant->id ?? null);

        return $key !== null ? (string) $key : null;
    }

    /**
     * `user:<id>` for authenticated calls (console session and personal API tokens of the same
     * user share one bucket, so extra tokens do not multiply the budget), `ip:<ip>` otherwise.
     */
    public static function principalKey(Request $request): string
    {
        $user = $request->user();

        return $user
            ? 'user:'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();
    }

    public static function authKey(Request $request): string
    {
        $login = $request->input('login')
            ?? $request->input('email')
            ?? $request->input('username')
            ?? '';

        $login = is_string($login) ? mb_strtolower(trim($login)) : '';

        return 'auth:'.$request->ip().'|'.$login;
    }

    public static function tooManyRequests(Request $request, array $headers): JsonResponse
    {
        $retryAfter = (int) ($headers['Retry-After'] ?? 60);

        return response()->json([
            'message' => __('api.rate_limit.exceeded', ['seconds' => $retryAfter]),
            'retry_after' => $retryAfter,
        ], 429, $headers);
    }
}
