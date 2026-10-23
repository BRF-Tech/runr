<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class NetworkCallerRemove extends Tool
{
    public function name(): string
    {
        return 'network_caller_remove';
    }

    public function title(): string
    {
        return 'Stop a project from calling this one';
    }

    public function description(): string
    {
        return 'Removes a project from the allow list of a project on the region\'s internal network; its calls are refused within seconds. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'caller' => Schema::string('The caller\'s internal name <project>.<organization>.', ['minLength' => 3, 'maxLength' => 120]),
            'confirm' => Schema::confirm('removing the caller'),
        ], ['project', 'caller', 'confirm']);
    }

    public function permissions(): array
    {
        return ['network.manage'];
    }

    public function group(): string
    {
        return 'configuration';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $caller = strtolower(trim((string) $arguments['caller']));
        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'network', 'callers', $caller));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The caller was not removed');
        }

        return ToolResult::ok(['removed' => $caller], $caller.' may no longer call the project.');
    }
}
