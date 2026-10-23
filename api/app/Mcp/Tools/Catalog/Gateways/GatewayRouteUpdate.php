<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GatewayRouteUpdate extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'gateway_route_update';
    }

    public function title(): string
    {
        return 'Set the authentication of a gateway route';
    }

    public function description(): string
    {
        return 'Sets how callers authenticate on one gateway route: public (anyone), key (an API key of a consumer, sent as X-Api-Key or Authorization: Bearer) or jwt (a token the issuer\'s JWKS verifies). gateway_only true makes the project reachable only through the gateway (its own URL stops serving). The region applies the change within seconds. A new route gets its authentication with gateway_route_add.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'gateway' => Schema::string('The gateway id (gateways_list).', ['minLength' => 26, 'maxLength' => 26]),
            'route' => Schema::string('The route: its id or path, e.g. "/orders".', ['minLength' => 1, 'maxLength' => 255]),
            'auth_mode' => Schema::enum(['public', 'key', 'jwt'], 'How callers authenticate.'),
            'jwks_url' => Schema::string('jwt: the JWKS URL of the token issuer.', ['maxLength' => 2048]),
            'issuer' => Schema::string('jwt: the expected "iss" claim (optional).', ['maxLength' => 255]),
            'audience' => Schema::string('jwt: the expected "aud" claim (optional).', ['maxLength' => 255]),
            'subject_claim' => Schema::string('jwt: the claim that names the consumer (default "sub").', ['maxLength' => 64]),
            'require_consumer' => Schema::boolean('jwt: refuse tokens whose subject is no consumer.'),
            'gateway_only' => Schema::boolean('The project\'s own URL stops serving; only the gateway reaches it.'),
        ], ['gateway', 'route']);
    }

    public function permissions(): array
    {
        return ['projects.update'];
    }

    public function group(): string
    {
        return 'gateways';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $gatewayId = (string) $arguments['gateway'];
        $route = $this->route($this->gateway($context, $gatewayId), (string) $arguments['route']);

        $body = self::compact([
            'auth_mode' => $arguments['auth_mode'] ?? null,
            'gateway_only' => $arguments['gateway_only'] ?? null,
        ]);
        if (isset($arguments['jwks_url'])) {
            $body['jwt'] = self::compact([
                'jwks_url' => $arguments['jwks_url'],
                'issuer' => $arguments['issuer'] ?? null,
                'audience' => $arguments['audience'] ?? null,
                'subject_claim' => $arguments['subject_claim'] ?? null,
                'require_consumer' => $arguments['require_consumer'] ?? null,
            ]);
        }
        if ($body === []) {
            throw new ToolError('Nothing to change: pass auth_mode, jwks_url or gateway_only.');
        }

        $result = $context->api()->patch(InternalApi::path('gateways', $gatewayId, 'routes', (string) $route['id']), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The route was not changed');
        }

        $updated = (array) $result->data();

        return ToolResult::ok(['route' => [
            'id' => $updated['id'] ?? $route['id'],
            'path' => $updated['path'] ?? $route['path'],
            'url' => $updated['url'] ?? $route['url'] ?? null,
            'auth_mode' => $updated['auth_mode'] ?? null,
            'gateway_only' => $updated['gateway_only'] ?? null,
        ]], sprintf('Route %s: %s.', $route['path'] ?? $route['id'], $updated['auth_mode'] ?? 'updated'));
    }
}
