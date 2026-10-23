<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiKeyCreate extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_key_create';
    }

    public function title(): string
    {
        return 'Create an API key';
    }

    public function description(): string
    {
        return 'Creates an API key for a consumer and returns it once — RunR keeps only its hash, so hand it to the consumer right away and do not repeat it elsewhere. Optionally limited to some routes (and methods), with a plan of its own and an expiry.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'consumer' => Schema::string('The consumer id (api_consumers_list).', ['minLength' => 26, 'maxLength' => 26]),
            'name' => Schema::string('Name of the key, e.g. "production".', ['minLength' => 1, 'maxLength' => 120]),
            'routes' => Schema::array(Schema::object([
                'route_id' => Schema::string('Route id (gateways_list).', ['minLength' => 26, 'maxLength' => 26]),
                'methods' => Schema::array(Schema::enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], 'HTTP method.'), 'Only these methods (leave out: every method).', 7),
            ], ['route_id']), 'Only these routes (leave out: every route the consumer may call).', 200),
            'plan' => Schema::string('A plan of its own for this key (plan id).', ['minLength' => 26, 'maxLength' => 26]),
            'expires_at' => Schema::string('Expiry, ISO 8601 (optional).', ['maxLength' => 40]),
        ], ['consumer', 'name']);
    }

    public function permissions(): array
    {
        return ['projects.update|api_products.manage'];
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
        $result = $context->api()->post(InternalApi::path('api-consumers', (string) $arguments['consumer'], 'keys'), self::compact([
            'name' => (string) $arguments['name'],
            'scopes' => isset($arguments['routes']) ? array_values((array) $arguments['routes']) : null,
            'plan_id' => $arguments['plan'] ?? null,
            'expires_at' => $arguments['expires_at'] ?? null,
        ]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The key was not created');
        }

        $key = (array) $result->data();

        return ToolResult::ok(['key' => [
            'id' => $key['id'] ?? null,
            'name' => $key['name'] ?? null,
            'prefix' => $key['prefix'] ?? null,
            'expires_at' => $key['expires_at'] ?? null,
            'secret' => $key['key'] ?? null,
        ]], 'Key created. It is shown this once: give it to the consumer now.');
    }
}
