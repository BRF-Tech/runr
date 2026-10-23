<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiKeyRotate extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_key_rotate';
    }

    public function title(): string
    {
        return 'Rotate an API key';
    }

    public function description(): string
    {
        return 'Replaces an API key with a new one (same routes, plan and expiry) and returns the new key once. The old key keeps working for overlap_minutes (default one day) so the consumer can switch without an outage; 0 ends it at once.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'key' => Schema::string('The key id.', ['minLength' => 26, 'maxLength' => 26]),
            'overlap_minutes' => Schema::integer('How long the old key keeps working (0–43200, default 1440).', 0, 43200),
        ], ['key']);
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
        $result = $context->api()->post(InternalApi::path('api-keys', (string) $arguments['key'], 'rotate'), self::compact([
            'overlap_minutes' => $arguments['overlap_minutes'] ?? null,
        ]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The key was not rotated');
        }

        $key = (array) $result->data();

        return ToolResult::ok(['key' => [
            'id' => $key['id'] ?? null,
            'prefix' => $key['prefix'] ?? null,
            'rotated_from_id' => $key['rotated_from_id'] ?? null,
            'secret' => $key['key'] ?? null,
        ]], 'New key created. It is shown this once: give it to the consumer now.');
    }
}
