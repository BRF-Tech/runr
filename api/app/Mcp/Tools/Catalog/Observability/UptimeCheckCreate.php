<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsUptime;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class UptimeCheckCreate extends Tool
{
    use ReadsUptime;

    public function name(): string
    {
        return 'uptime_check_create';
    }

    public function title(): string
    {
        return 'Create an uptime check';
    }

    public function description(): string
    {
        return 'Creates a synthetic uptime check of a project: a path of its endpoint (default /) or an absolute URL, probed every interval by the RunR API or the agents of regions, with the accepted status codes and an optional body pattern. Going down opens an incident and fires uptime alerts. Then uptime_check_run probes it once.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()] + self::uptimeProperties(), ['project', 'name']);
    }

    public function permissions(): array
    {
        return ['uptime.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $body = array_diff_key($arguments, ['project' => true]);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'uptime-checks'), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The uptime check was not created');
        }

        $check = self::uptimeSummary((array) $result->data());

        return ToolResult::ok(['check' => $check], 'Uptime check "'.($check['name'] ?? '?').'" created.');
    }
}
