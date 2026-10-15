<?php

namespace App\Mcp\Tools\Catalog\Bindings;

use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class BindingCreate extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'binding_create';
    }

    public function title(): string
    {
        return 'Create a binding';
    }

    public function description(): string
    {
        return 'Creates a binding: "kv" (Redis with its own user and key prefix), "object" (an S3 bucket), "postgres" (a database, shared or a dedicated cluster) in a region, or "external" (credentials you supply, e.g. a third-party API key). Provisioning runs in the region; attach the binding to a project with binding_attach, then deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('Binding name: lower-case letters, digits and dashes.', ['minLength' => 1, 'maxLength' => 30, 'pattern' => '^[a-z]([-a-z0-9]*[a-z0-9])?$']),
            'type' => Schema::enum(['kv', 'object', 'postgres', 'external'], 'What to create.'),
            'plan' => Schema::enum(['shared', 'dedicated'], 'postgres only: shared database (default) or dedicated cluster.'),
            'region' => Schema::string('Region id or name; required except for external.', ['maxLength' => 64]),
            'settings' => Schema::object([
                'memory_mb' => Schema::integer('kv: memory limit in MiB.', 8),
                'quota_gb' => Schema::integer('object: bucket quota in GB.', 1),
                'storage_gb' => Schema::integer('postgres: storage in GB.', 1),
                'connection_limit' => Schema::integer('postgres: connection limit.', 1),
                'statement_timeout' => Schema::string('postgres: statement timeout, e.g. "30s".', ['maxLength' => 16]),
                'instances' => Schema::integer('postgres dedicated: instances.', 1),
                'backups' => Schema::boolean('postgres dedicated: backups.'),
            ]),
            'values' => Schema::map(['type' => 'string', 'maxLength' => 4096], 'external only: ENV_NAME → value (the credentials the binding injects).') + [
                'propertyNames' => ['type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]*$', 'maxLength' => 64],
            ],
        ], ['name', 'type']);
    }

    public function permissions(): array
    {
        return ['bindings.manage'];
    }

    public function group(): string
    {
        return 'bindings';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $type = (string) $arguments['type'];
        $payload = array_filter([
            'name' => (string) $arguments['name'],
            'type' => $type,
            'plan' => $arguments['plan'] ?? null,
            'settings' => ! empty($arguments['settings']) ? (array) $arguments['settings'] : null,
            'values' => $type === 'external' ? (array) ($arguments['values'] ?? []) : null,
        ], fn ($v) => $v !== null);

        if ($type !== 'external') {
            $payload['knative_region_id'] = $this->regionId($context, $arguments['region'] ?? null);
        }

        $result = $context->api()->post('/bindings', $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The binding was not created');
        }

        $binding = (array) $result->data();
        $context->setTarget('binding', (string) ($binding['id'] ?? ''), (string) ($binding['name'] ?? ''));

        return ToolResult::ok(['binding' => BindingsList::summary($binding)], 'Binding '.($binding['name'] ?? '?').' created ('.($binding['status'] ?? 'provisioning').'). Attach it with binding_attach.');
    }
}
