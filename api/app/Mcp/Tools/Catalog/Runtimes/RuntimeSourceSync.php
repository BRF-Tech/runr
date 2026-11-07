<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/** runtime_source_sync: read a runtime source now. */
class RuntimeSourceSync extends Tool
{
    public function name(): string
    {
        return 'runtime_source_sync';
    }

    public function title(): string
    {
        return 'Read a runtime source now';
    }

    public function description(): string
    {
        return 'Reads a runtime source now (id or name), also when its revision did not change: its runtimes are created, updated or orphaned. Answers with the status, the revision and every problem with its file and JSON path.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'source' => Schema::string('The source (id or name).', ['minLength' => 1, 'maxLength' => 64]),
        ], ['source']);
    }

    public function permissions(): array
    {
        return ['runtimes.manage|runtimes.use_custom'];
    }

    public function group(): string
    {
        return 'runtimes';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $list = $context->api()->get('/runtime-sources');
        if (! $list->ok()) {
            throw ToolError::fromApi($list);
        }
        $id = RuntimeSourcesList::resolve(array_values(array_filter((array) $list->get('data', []), 'is_array')), (string) $arguments['source']);

        $result = $context->api()->post(InternalApi::path('runtime-sources', $id, 'sync'));
        $source = (array) $result->data();
        if (! $result->ok() && $source === []) {
            throw ToolError::fromApi($result, 'The runtime source was not read');
        }

        return ToolResult::ok(['source' => RuntimeSourcesList::summary($source)],
            'Runtime source '.($source['name'] ?? '?').': '.($source['status'] ?? '?').'.');
    }
}
