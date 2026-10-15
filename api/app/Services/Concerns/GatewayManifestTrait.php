<?php

namespace App\Services\Concerns;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Setting;

trait GatewayManifestTrait
{
    public function buildIngressRoute(Gateway $gateway): array
    {
        $region = $gateway->knativeRegion;
        $namespace = $region->project_namespace ?? $region->name;
        $baseDomain = Setting::get('base_domain');
        $host = "{$gateway->subdomain}.{$region->name}.{$baseDomain}";

        $rateLimit = $gateway->rate_limit_average ? $this->rateLimitMiddlewareName($gateway) : null;

        $routes = [];
        foreach ($gateway->routes()->with('project')->get() as $route) {
            $routes[] = $this->buildRouteRule($route, $host, $namespace, $rateLimit);
        }

        if (empty($routes)) {
            $routes[] = [
                'match' => "Host(`{$host}`) && PathPrefix(`/`)",
                'kind' => 'Rule',
                'services' => [['name' => 'noop@internal', 'kind' => 'TraefikService']],
            ];
        }

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'IngressRoute',
            'metadata' => [
                'name' => "gw-{$gateway->id}",
                'namespace' => $namespace,
                'labels' => [
                    'runr/gateway-id' => $gateway->id,
                    'runr/user-id' => (string) $gateway->user_id,
                ],
            ],
            'spec' => [
                'entryPoints' => ['websecure'],
                'routes' => $routes,
            ],
        ];
    }

    public function buildStripPrefixMiddleware(GatewayRoute $route, string $namespace): array
    {
        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'name' => "gw-strip-{$route->id}",
                'namespace' => $namespace,
            ],
            'spec' => [
                'stripPrefix' => [
                    'prefixes' => [$route->path],
                ],
            ],
        ];
    }

    public function rateLimitMiddlewareName(Gateway $gateway): string
    {
        return "gw-ratelimit-{$gateway->id}";
    }

    /**
     * Optional Traefik rateLimit Middleware in front of every route of the gateway.
     * `average` requests per second with `burst` headroom (defaults to `average`); Traefik's
     * default source criterion applies (limit per client IP). Null when the gateway has no limit.
     */
    public function buildRateLimitMiddleware(Gateway $gateway, string $namespace): ?array
    {
        if (! $gateway->rate_limit_average) {
            return null;
        }

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'name' => $this->rateLimitMiddlewareName($gateway),
                'namespace' => $namespace,
                'labels' => [
                    'runr/gateway-id' => $gateway->id,
                ],
            ],
            'spec' => [
                'rateLimit' => [
                    'average' => (int) $gateway->rate_limit_average,
                    'burst' => (int) ($gateway->rate_limit_burst ?: $gateway->rate_limit_average),
                    'period' => '1s',
                ],
            ],
        ];
    }

    private function buildRouteRule(GatewayRoute $route, string $host, string $namespace, ?string $rateLimitMiddleware = null): array
    {
        $match = "Host(`{$host}`) && PathPrefix(`{$route->path}`)";

        if (! empty($route->methods)) {
            $methodList = implode('`, `', $route->methods);
            $match .= " && Method(`{$methodList}`)";
        }

        $rule = [
            'match' => $match,
            'kind' => 'Rule',
            'services' => [[
                'name' => $route->project->slug,
                'port' => 80,
            ]],
        ];

        $middlewares = [];
        if ($rateLimitMiddleware) {
            $middlewares[] = ['name' => $rateLimitMiddleware, 'namespace' => $namespace];
        }
        if ($route->strip_prefix) {
            $middlewares[] = ['name' => "gw-strip-{$route->id}", 'namespace' => $namespace];
        }
        if ($middlewares) {
            $rule['middlewares'] = $middlewares;
        }

        return $rule;
    }
}
