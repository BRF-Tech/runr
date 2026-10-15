<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RuntimeGet extends Tool
{
    public function name(): string
    {
        return 'runtime_get';
    }

    public function title(): string
    {
        return 'Show a runtime';
    }

    public function description(): string
    {
        return 'Shows a runtime with its manifest (runtime.yaml) and its starter files for a new project — the shape a handler must have. Use it before writing code for a runtime you have not used yet.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'runtime' => Schema::string('Runtime id (runtimes_list).', ['minLength' => 1, 'maxLength' => 32, 'pattern' => '^[a-z][a-z0-9-]*$']),
            'service_type' => Schema::enum(['xaas', 'faas'], 'Starter files of this project type (default faas: entry file plus dependency file).', 'faas'),
        ], ['runtime']);
    }

    public function permissions(): array
    {
        return [];
    }

    public function group(): string
    {
        return 'runtimes';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['runtime'];

        $runtime = $context->api()->get(InternalApi::path('runtimes', $id));
        if (! $runtime->ok()) {
            throw ToolError::fromApi($runtime, "Reading runtime {$id}");
        }
        $data = (array) $runtime->data();
        $context->setTarget('runtime', $id, isset($data['name']) ? (string) $data['name'] : null);

        $template = $context->api()->get(InternalApi::path('runtimes', $id, 'template'), ['service_type' => (string) ($arguments['service_type'] ?? 'faas')]);

        return ToolResult::ok(array_filter([
            'runtime' => RuntimesList::summary($data),
            'manifest' => $data['manifest_source'] ?? null,
            'starter_files' => $template->ok() ? (array) $template->get('files', []) : null,
        ], fn ($v) => $v !== null), 'Runtime '.($data['name'] ?? $id).'.');
    }
}
