<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * A secret variable as a reference to a secret store of the organization (docs/secret-stores.md):
 * PUT …/secrets/{name} with {"reference": {store_id, path, key}}. RunR never sees the value.
 */
class SecretRefSet extends Tool
{
    public function name(): string
    {
        return 'secret_ref_set';
    }

    public function title(): string
    {
        return 'Set a secret store reference';
    }

    public function description(): string
    {
        return 'Makes a secret variable of a project a reference to a value in one of the organization\'s secret stores (Vault / OpenBao, AWS Secrets Manager, GCP Secret Manager, Azure Key Vault): store (name or ID, secret_stores_list shows them), path and an optional key inside the secret. External Secrets Operator in the project\'s region reads it with the region\'s identity; RunR never sees the value. Takes effect with the next deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'name' => Schema::string('Name of the secret variable.', ['minLength' => 1, 'maxLength' => 255, 'pattern' => '^[A-Za-z_][A-Za-z0-9_]*$']),
            'store' => Schema::string('Name or ID of the secret store.', ['minLength' => 1, 'maxLength' => 63]),
            'path' => Schema::string('Path of the secret in the store (Vault: the path under the KV mount; AWS / GCP / Azure: the secret\'s name).', ['minLength' => 1, 'maxLength' => 512]),
            'key' => Schema::string('Key inside the secret (a field of a JSON or KV secret); omit for the whole value.', ['maxLength' => 255]),
        ], ['project', 'name', 'store', 'path']);
    }

    public function permissions(): array
    {
        return ['env.edit', 'secret_stores.view'];
    }

    public function group(): string
    {
        return 'configuration';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $name = (string) $arguments['name'];

        $stores = $context->api()->get(InternalApi::path('secret-stores'));
        if (! $stores->ok()) {
            throw ToolError::fromApi($stores, 'Listing secret stores');
        }
        $wanted = (string) $arguments['store'];
        $store = collect((array) $stores->get('data', []))->first(fn ($s) => ($s['id'] ?? null) === $wanted || ($s['name'] ?? null) === $wanted);
        if (! $store) {
            throw new ToolError("No secret store named {$wanted}; secret_stores_list shows the organization's stores.");
        }

        $result = $context->api()->put(InternalApi::path('projects', $projectId, 'secrets', $name), [
            'reference' => array_filter([
                'store_id' => (string) $store['id'],
                'path' => (string) $arguments['path'],
                'key' => isset($arguments['key']) && $arguments['key'] !== '' ? (string) $arguments['key'] : null,
            ], fn ($v) => $v !== null),
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, "Setting reference {$name}");
        }

        return ToolResult::ok([
            'name' => $name,
            'store' => $store['name'] ?? $wanted,
            'path' => (string) $arguments['path'],
            'key' => $arguments['key'] ?? null,
            'requires_deploy' => true,
        ], "{$name} refers to {$store['name']}:{$arguments['path']}. Deploy to apply.");
    }
}
