<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/** runtime_source_remove: disconnect a runtime source. */
class RuntimeSourceRemove extends Tool
{
    public function name(): string
    {
        return 'runtime_source_remove';
    }

    public function title(): string
    {
        return 'Remove a runtime source';
    }

    public function description(): string
    {
        return 'Removes a runtime source (id or name): its runtimes that no project uses are deleted, the others stay orphaned (no new projects; their projects keep running and deploying until the sunset after the grace period). RunR\'s catalog can not be removed. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'source' => Schema::string('The source (id or name).', ['minLength' => 1, 'maxLength' => 64]),
            'confirm' => Schema::confirm('removing the runtime source'),
        ], ['source', 'confirm']);
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $list = $context->api()->get('/runtime-sources');
        if (! $list->ok()) {
            throw ToolError::fromApi($list);
        }
        $id = RuntimeSourcesList::resolve(array_values(array_filter((array) $list->get('data', []), 'is_array')), (string) $arguments['source']);

        $result = $context->api()->delete(InternalApi::path('runtime-sources', $id));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The runtime source was not removed');
        }

        return ToolResult::ok(['removed' => $id], 'Runtime source removed.');
    }
}
