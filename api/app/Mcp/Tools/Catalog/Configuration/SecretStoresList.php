<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/** The organization's secret stores (GET /secret-stores, docs/secret-stores.md): settings, never credentials. */
class SecretStoresList extends Tool
{
    public function name(): string
    {
        return 'secret_stores_list';
    }

    public function title(): string
    {
        return 'List secret stores';
    }

    public function description(): string
    {
        return 'Lists the organization\'s secret stores — Vault / OpenBao, AWS Secrets Manager, GCP Secret Manager, Azure Key Vault — with their provider, how they authenticate (the region\'s identity; RunR keeps no credential), how many project secrets refer to each and their status per region. secret_ref_set makes a secret variable a reference to one of them.';
    }

    public function inputSchema(): array
    {
        return Schema::object([]);
    }

    public function permissions(): array
    {
        return ['secret_stores.view'];
    }

    public function group(): string
    {
        return 'configuration';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get(InternalApi::path('secret-stores'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Listing secret stores');
        }

        $stores = array_map(fn (array $s) => [
            'id' => $s['id'] ?? null,
            'name' => $s['name'] ?? null,
            'provider' => $s['provider'] ?? null,
            'auth' => $s['auth'] ?? null,
            'references' => $s['references'] ?? 0,
            'regions' => $s['regions'] ?? (object) [],
        ], (array) $result->get('data', []));

        return ToolResult::ok(['stores' => $stores], count($stores).' secret store(s).');
    }
}
