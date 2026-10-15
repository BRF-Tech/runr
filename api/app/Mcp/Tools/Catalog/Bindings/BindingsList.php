<?php

namespace App\Mcp\Tools\Catalog\Bindings;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class BindingsList extends Tool
{
    public function name(): string
    {
        return 'bindings_list';
    }

    public function title(): string
    {
        return 'List bindings';
    }

    public function description(): string
    {
        return 'Lists the organization\'s bindings — managed KV (Redis), object storage (S3), Postgres databases and external credentials — with status, settings, the environment variable names they inject and the projects they are attached to. Credential values are never listed.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'type' => Schema::enum(['kv', 'object', 'postgres', 'external'], 'Only bindings of this type.'),
            'region' => Schema::string('Only bindings of this region (region id).', ['maxLength' => 64]),
        ]);
    }

    public function permissions(): array
    {
        return ['bindings.view'];
    }

    public function group(): string
    {
        return 'bindings';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/bindings', [
            'type' => $arguments['type'] ?? null,
            'knative_region_id' => $arguments['region'] ?? null,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result);
        }

        $bindings = array_values(array_map([self::class, 'summary'], array_filter((array) $result->get('data', []), 'is_array')));

        return ToolResult::ok(['bindings' => $bindings], count($bindings).' binding(s).');
    }

    public static function summary(array $binding): array
    {
        return array_filter([
            'id' => $binding['id'] ?? null,
            'name' => $binding['name'] ?? null,
            'type' => $binding['type'] ?? null,
            'plan' => $binding['plan'] ?? null,
            'region' => $binding['region']['name'] ?? $binding['knative_region_id'] ?? null,
            'status' => $binding['status'] ?? null,
            'status_message' => $binding['status_message'] ?? null,
            'settings' => $binding['settings'] ?? null,
            'env_keys' => $binding['env_keys'] ?? null,
            'usage_bytes' => $binding['usage_bytes'] ?? null,
            'attachments' => isset($binding['attachments']) ? array_values(array_map(fn ($a) => array_filter([
                'id' => $a['id'] ?? null,
                'project' => $a['project']['name'] ?? $a['project_id'] ?? null,
                'env_prefix' => $a['env_prefix'] ?? null,
                'env_names' => $a['env_names'] ?? null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== []), array_filter((array) $binding['attachments'], 'is_array'))) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
