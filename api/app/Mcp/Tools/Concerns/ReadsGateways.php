<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Gateways and the API product (docs/api-product.md) for the gateway tools: compact summaries and
 * the lookup of a gateway route by id or path.
 */
trait ReadsGateways
{
    /** @return array<string, mixed> */
    protected function gateway(ToolContext $context, string $gatewayId): array
    {
        $result = $context->api()->get(InternalApi::path('gateways', $gatewayId));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The gateway was not found');
        }

        return (array) $result->data();
    }

    /** A route of the gateway by id or path. */
    protected function route(array $gateway, string $reference): array
    {
        foreach ((array) ($gateway['routes'] ?? []) as $route) {
            if (($route['id'] ?? null) === $reference || ($route['path'] ?? null) === $reference) {
                return $route;
            }
        }

        throw new ToolError("No route {$reference} on this gateway (gateways_list shows the routes).");
    }

    /** @return array<string, mixed> */
    protected static function gatewaySummary(array $gateway): array
    {
        return [
            'id' => $gateway['id'] ?? null,
            'host' => $gateway['host'] ?? null,
            'custom_domain' => $gateway['custom_domain'] ?? null,
            'region' => $gateway['region']['name'] ?? null,
            'status' => $gateway['status'] ?? null,
            // pending / error: why, e.g. key and JWT routes answer 503 until gateway-auth is ready
            'status_message' => $gateway['status_message'] ?? null,
            'rate_limit' => $gateway['rate_limit'] ?? null,
            'developer_page' => $gateway['developer_page'] ?? false,
            'routes' => array_map(fn ($r) => [
                'id' => $r['id'] ?? null,
                'path' => $r['path'] ?? null,
                'methods' => $r['methods'] ?? null,
                'project' => $r['project']['name'] ?? null,
                // the project environment the route leads to (production: its own workload)
                'environment' => $r['environment'] ?? 'production',
                'url' => $r['url'] ?? null,
                'auth_mode' => $r['auth_mode'] ?? 'public',
                'gateway_only' => $r['gateway_only'] ?? false,
            ], (array) ($gateway['routes'] ?? [])),
        ];
    }

    /** Keys of a JSON answer without nulls. */
    protected static function compact(array $values): array
    {
        return array_filter($values, fn ($v) => $v !== null);
    }
}
