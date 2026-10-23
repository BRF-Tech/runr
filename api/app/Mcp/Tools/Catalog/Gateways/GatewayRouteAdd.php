<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GatewayRouteAdd extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'gateway_route_add';
    }

    public function title(): string
    {
        return 'Route a gateway path to a project';
    }

    public function description(): string
    {
        return 'Adds a route to a gateway: a path that leads to a project\'s own workload, or with environment to one of its environments (the gateway must be in the region the workload runs in). Give auth_mode key or jwt (with jwks_url) to create the route with its authentication: it is never public for a moment. gateway_only true makes the workload reachable only through the gateway. The answer has the route\'s URL.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'gateway' => Schema::string('The gateway id (gateways_list).', ['minLength' => 26, 'maxLength' => 26]),
            'project' => Schema::project(),
            'environment' => Schema::string('Environment name or id the route leads to (default: production, the project\'s own workload).', ['maxLength' => 64]),
            'path' => Schema::string('Path of the route, e.g. "/orders".', ['minLength' => 1, 'maxLength' => 255, 'pattern' => '^/[a-zA-Z0-9/_-]*$']),
            'methods' => Schema::array(Schema::enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'An HTTP method.'), 'Methods the route accepts (default: every method).'),
            'auth_mode' => Schema::enum(['public', 'key', 'jwt'], 'How callers authenticate (default public).'),
            'jwks_url' => Schema::string('jwt: the JWKS URL of the token issuer.', ['maxLength' => 2048]),
            'issuer' => Schema::string('jwt: the expected "iss" claim (optional).', ['maxLength' => 255]),
            'audience' => Schema::string('jwt: the expected "aud" claim (optional).', ['maxLength' => 255]),
            'subject_claim' => Schema::string('jwt: the claim that names the consumer (default "sub").', ['maxLength' => 64]),
            'require_consumer' => Schema::boolean('jwt: refuse tokens whose subject is no consumer.'),
            'gateway_only' => Schema::boolean('The workload\'s own URL stops serving; only the gateway reaches it.'),
        ], ['gateway', 'project', 'path']);
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
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $gatewayId = (string) $arguments['gateway'];
        $body = self::compact([
            'project_id' => $context->projectId((string) $arguments['project']),
            'environment' => isset($arguments['environment']) && $arguments['environment'] !== '' ? (string) $arguments['environment'] : null,
            'path' => '/'.ltrim((string) $arguments['path'], '/'),
            'methods' => isset($arguments['methods']) ? array_values((array) $arguments['methods']) : null,
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

        // one call: the route reaches the region with its authentication
        $result = $context->api()->post(InternalApi::path('gateways', $gatewayId, 'routes'), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The route was not added');
        }

        $route = (array) $result->data();

        return ToolResult::ok(['route' => [
            'id' => $route['id'] ?? null,
            'path' => $route['path'] ?? $body['path'],
            'project' => $route['project']['name'] ?? null,
            'environment' => $route['environment'] ?? 'production',
            'url' => $route['url'] ?? null,
            'auth_mode' => $route['auth_mode'] ?? null,
            'gateway_only' => $route['gateway_only'] ?? null,
        ]], sprintf('Route %s added (%s)%s.', $route['path'] ?? $body['path'], $route['auth_mode'] ?? 'public', isset($route['url']) ? ': '.$route['url'] : ''));
    }
}
