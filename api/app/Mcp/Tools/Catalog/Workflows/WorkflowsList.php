<?php

namespace App\Mcp\Tools\Catalog\Workflows;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WorkflowsList extends Tool
{
    public function name(): string
    {
        return 'workflows_list';
    }

    public function title(): string
    {
        return 'List durable workflows';
    }

    public function description(): string
    {
        return 'Lists the durable workflows of a project (a project with the "workflow" trigger): name, triggers (events, cron, queue), retries, whether active, run counts by status, and the sync state of the definitions. Start a run with workflow_run_start or send an event with event_send.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()], ['project']);
    }

    public function permissions(): array
    {
        return ['workflows.view'];
    }

    public function group(): string
    {
        return 'workflows';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'workflows'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the workflows');
        }

        $workflows = array_values(array_map(fn (array $w) => array_filter([
            'name' => $w['name'] ?? null,
            'description' => $w['description'] ?? null,
            'triggers' => $w['triggers'] ?? null,
            'retries' => $w['retries'] ?? null,
            'timeout' => $w['timeout'] ?? null,
            'active' => $w['is_active'] ?? null,
            'archived' => ! empty($w['archived']) ? true : null,
            'run_counts' => $w['run_counts'] ?? null,
            'last_run_at' => $w['last_run_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== []), array_filter((array) $result->get('data', []), 'is_array')));

        $app = (array) $result->get('app', []);

        return ToolResult::ok(array_filter([
            'workflows' => $workflows,
            'app' => array_filter([
                'workflow_project' => $app['is_workflow_project'] ?? null,
                'endpoint_url' => $app['endpoint_url'] ?? null,
                'sync_status' => $app['sync_status'] ?? null,
                'sync_error' => $app['sync_error'] ?? null,
            ], fn ($v) => $v !== null),
        ]), count($workflows).' workflow(s).');
    }
}
