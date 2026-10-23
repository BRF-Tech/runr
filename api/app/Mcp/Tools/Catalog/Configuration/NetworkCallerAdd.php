<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class NetworkCallerAdd extends Tool
{
    public function name(): string
    {
        return 'network_caller_add';
    }

    public function title(): string
    {
        return 'Allow a project to call this one';
    }

    public function description(): string
    {
        return 'Adds a project to the allow list of a project on the region\'s internal network: from then on calls signed with the caller\'s RUNR_INTERNAL_TOKEN are admitted. Projects are isolated from each other otherwise, also inside one organization. The caller is a project of this organization (name or id) or another organization\'s project by its internal name <project>.<organization> — a cross-organization grant, which the platform may switch off.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'caller' => Schema::string('The calling project: name, id, or internal name <project>.<organization>.', ['minLength' => 1, 'maxLength' => 120]),
        ], ['project', 'caller']);
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
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'network', 'callers'), ['caller' => (string) $arguments['caller']]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The caller was not allowed');
        }
        $caller = (array) $result->data();

        return ToolResult::ok(['caller' => $caller], ($caller['caller'] ?? $arguments['caller']).' may call the project now'
            .(! empty($caller['cross_tenant']) ? ' (a grant to another organization).' : '.'));
    }
}
