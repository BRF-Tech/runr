<?php

namespace App\Support\Rbac;

use Illuminate\Routing\Route;

/**
 * config/route_permissions.php: the requirement of an API route ("METHOD uri" without "api/").
 */
class RoutePermissions
{
    public const AUTHENTICATED = '@authenticated';

    public const PLATFORM = '@platform';

    /**
     * '@platform:billing.view' — platform administrators; an API token of one must carry the
     * permission (or '*'). Lets the operator's systems use narrowly scoped tokens on platform routes.
     */
    public const PLATFORM_SCOPED = 'platform';

    public const POLICY = '@policy';

    /** Does the route sit behind authentication (auth / auth:sanctum)? */
    public static function requiresAuthentication(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    /** Does the route authorize itself with Laravel's `can:` middleware? */
    public static function hasCanMiddleware(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> lookup keys of the route ("GET projects/{project}", …) */
    public static function keys(Route $route): array
    {
        $uri = preg_replace('#^api/#', '', ltrim($route->uri(), '/'));

        return array_values(array_map(
            fn (string $method) => "{$method} {$uri}",
            array_diff($route->methods(), ['HEAD', 'OPTIONS']),
        ));
    }

    /** @return string|list<string>|null */
    public static function requirement(Route $route): string|array|null
    {
        $map = config('route_permissions', []);
        foreach (self::keys($route) as $key) {
            if (array_key_exists($key, $map)) {
                return $map[$key];
            }
        }

        return null;
    }

    /**
     * Parse a requirement into [mode, permissions]: mode 'all' | 'any' | 'anywhere' | 'platform'
     * (platform administrator + token permission, any of them) | a marker.
     *
     * @param  string|list<string>  $requirement
     * @return array{0: string, 1: list<string>}
     */
    public static function parse(string|array $requirement): array
    {
        if (is_array($requirement)) {
            return ['all', array_values($requirement)];
        }
        if (str_starts_with($requirement, self::PLATFORM.':')) {
            return [self::PLATFORM_SCOPED, explode('|', substr($requirement, strlen(self::PLATFORM) + 1))];
        }
        if (str_starts_with($requirement, '@')) {
            return [$requirement, []];
        }
        if (str_ends_with($requirement, ':any')) {
            return ['anywhere', [substr($requirement, 0, -4)]];
        }

        return ['any', explode('|', $requirement)];
    }
}
