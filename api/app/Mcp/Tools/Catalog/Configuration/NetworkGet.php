<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class NetworkGet extends Tool
{
    public function name(): string
    {
        return 'network_get';
    }

    public function title(): string
    {
        return 'Show a project\'s network';
    }

    public function description(): string
    {
        return 'Shows who reaches a project: its access mode (public, allowlist: public from listed IP ranges only, internal: no public URL), its internal name and URL (<project>.<organization>.runr.internal) and the projects allowed to call it on the region\'s internal network. A caller sends its RUNR_INTERNAL_TOKEN env var in the X-Runr-Internal-Token header.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
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
        $projectId = $context->projectId((string) $arguments['project']);
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'network'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The network settings could not be read');
        }
        $network = (array) $result->data();

        return ToolResult::ok(['network' => $network], sprintf(
            'Access %s; internal URL %s; %d allowed caller(s).',
            $network['access_mode'] ?? '?', $network['internal_url'] ?? '?', count((array) ($network['callers'] ?? [])),
        ));
    }
}
