<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiKeyRevoke extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_key_revoke';
    }

    public function title(): string
    {
        return 'Revoke an API key';
    }

    public function description(): string
    {
        return 'Revokes an API key: the gateway refuses it (401) within seconds. This cannot be undone — the consumer needs a new key. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'key' => Schema::string('The key id.', ['minLength' => 26, 'maxLength' => 26]),
            'confirm' => Schema::confirm('revoking the key'),
        ], ['key', 'confirm']);
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->post(InternalApi::path('api-keys', (string) $arguments['key'], 'revoke'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The key was not revoked');
        }

        $key = (array) $result->data();

        return ToolResult::ok(['key' => [
            'id' => $key['id'] ?? null,
            'prefix' => $key['prefix'] ?? null,
            'status' => $key['status'] ?? null,
        ]], sprintf('Key %s revoked.', $key['prefix'] ?? $arguments['key']));
    }
}
