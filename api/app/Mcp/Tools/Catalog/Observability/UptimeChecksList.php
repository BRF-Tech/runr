<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ReadsUptime;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class UptimeChecksList extends Tool
{
    use ReadsUptime;

    public function name(): string
    {
        return 'uptime_checks_list';
    }

    public function title(): string
    {
        return 'List uptime checks';
    }

    public function description(): string
    {
        return 'Synthetic uptime checks of a project with their state (up, down, unknown), uptime over 24 h / 7 d / 30 d, average response time and the last result.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()], ['project']);
    }

    public function permissions(): array
    {
        return ['metrics.view'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $checks = array_map([self::class, 'uptimeSummary'], $this->uptimeChecks($context, $projectId));
        $down = count(array_filter($checks, fn ($c) => ($c['status'] ?? null) === 'down'));

        return ToolResult::ok(['checks' => $checks, 'count' => count($checks), 'down' => $down], count($checks)." uptime check(s), {$down} down.");
    }
}
