<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class UptimeIncidentsList extends Tool
{
    public function name(): string
    {
        return 'uptime_incidents_list';
    }

    public function title(): string
    {
        return 'List uptime incidents';
    }

    public function description(): string
    {
        return 'Incidents of a project\'s uptime checks, newest first: open or resolved, when they started and ended, how long they lasted, the cause and the failed checks.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'status' => Schema::enum(['open', 'resolved'], 'Only open or only resolved incidents.'),
            'limit' => Schema::integer('At most this many incidents (default 20).', 1, 100),
        ], ['project']);
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

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'uptime-incidents'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the incidents');
        }

        $incidents = array_values(array_filter((array) $result->data(), 'is_array'));
        if (isset($arguments['status'])) {
            $incidents = array_values(array_filter($incidents, fn ($i) => ($i['status'] ?? null) === $arguments['status']));
        }
        $incidents = array_slice($incidents, 0, (int) ($arguments['limit'] ?? 20));
        $open = count(array_filter($incidents, fn ($i) => ($i['status'] ?? null) === 'open'));

        return ToolResult::ok(['incidents' => $incidents, 'count' => count($incidents), 'open' => $open], count($incidents)." incident(s), {$open} open.");
    }
}
