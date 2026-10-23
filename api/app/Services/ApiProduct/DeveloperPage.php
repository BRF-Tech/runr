<?php

namespace App\Services\ApiProduct;

use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Services\Observability\TenantScope;

/**
 * The public developer page of a gateway (docs/api-product.md#developer-page), when its owner
 * switched it on: the gateway's description, base URL(s), routes (path, methods, authentication)
 * and how to send a key or token. No sign-up in this version — consumers get their keys from the
 * owner. Addressed by "<organization>.<gateway id>" (organization "_" = the central database), so
 * the page is found without a session. With developer_page_metrics each key / JWT route shows its
 * success rate and latency percentiles of the last seven days (routes[].health) — never counts or
 * consumers.
 */
class DeveloperPage
{
    public const METRICS_DAYS = 7;

    public static function keyFor(Gateway $gateway): string
    {
        return GatewayAuthRevision::scope().'.'.$gateway->id;
    }

    /** @return array{0: string|null, 1: string}|null [tenant id (null: central), gateway id] */
    public static function parseKey(string $key): ?array
    {
        if (! preg_match('/^([A-Za-z0-9_-]{1,64})\.([0-9A-Za-z]{26})$/', $key, $m)) {
            return null;
        }

        return [$m[1] === GatewayAuthScope::CENTRAL ? null : $m[1], $m[2]];
    }

    /** The page, or null when there is none (unknown key, page switched off). */
    public function find(string $key): ?array
    {
        $parsed = self::parseKey($key);
        if ($parsed === null) {
            return null;
        }
        [$tenantId, $gatewayId] = $parsed;

        return TenantScope::run($tenantId, function () use ($gatewayId) {
            $gateway = Gateway::query()->with(['routes', 'knativeRegion'])->find($gatewayId);

            return $gateway && $gateway->developer_page ? $this->payload($gateway) : null;
        });
    }

    public function payload(Gateway $gateway): array
    {
        $domains = app(GatewayDomains::class);
        $hosts = array_values(array_filter([$gateway->custom_domain, $domains->defaultHost($gateway)]));
        // the owner's choice: the measured health of the key / JWT routes, last 7 days
        $health = $gateway->developer_page_metrics ? app(ApiUsageReport::class)->routeHealth($gateway, self::METRICS_DAYS) : [];

        return [
            'name' => $gateway->subdomain,
            'description' => $gateway->description,
            'base_urls' => array_map(fn ($host) => "https://{$host}", $hosts),
            'routes' => $gateway->routes->map(fn (GatewayRoute $route) => [
                'path' => $route->path,
                'methods' => $route->methods ?: null,
                'auth' => $route->auth_mode ?: GatewayRoute::AUTH_PUBLIC,
                'jwt_issuer' => $route->auth_mode === GatewayRoute::AUTH_JWT ? GatewayAuthConfig::jwt($route)['issuer'] : null,
            ] + ($gateway->developer_page_metrics ? ['health' => $health[$route->path] ?? null] : []))->values()->all(),
            'metrics_days' => $gateway->developer_page_metrics ? self::METRICS_DAYS : null,
            'key_headers' => ['X-Api-Key', 'Authorization: Bearer'],
            'rate_limit_headers' => ['RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset', 'Retry-After'],
        ];
    }
}
