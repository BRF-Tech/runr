<?php

namespace App\Mcp\Tools\Catalog\Operations;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class MetricsSummary extends Tool
{
    public function name(): string
    {
        return 'metrics_summary';
    }

    public function title(): string
    {
        return 'Metrics summary';
    }

    public function description(): string
    {
        return 'Summarises a project\'s recorded metrics over the last 7, 30 or 90 days: CPU and memory use, requests and pod sessions (cold starts, run time). Use it to size a project (project_update) or to spot load changes.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'period' => Schema::enum(['7d', '30d', '90d'], 'Time window (default 7d).', '7d'),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['metrics.view'];
    }

    public function group(): string
    {
        return 'operations';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $period = (string) ($arguments['period'] ?? '7d');

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'metrics', 'summary'), ['period' => $period]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the metrics');
        }

        return ToolResult::ok([
            'period' => $period,
            'metrics' => $result->get('metrics'),
            'sessions' => $result->get('sessions'),
        ], "Metrics of the last {$period}.");
    }
}
