<?php

namespace App\Mcp\Tools\Catalog\Workflows;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WorkflowRunsList extends Tool
{
    private const STATUSES = ['queued', 'running', 'sleeping', 'waiting', 'completed', 'failed', 'cancelled', 'timed_out'];

    public function name(): string
    {
        return 'workflow_runs_list';
    }

    public function title(): string
    {
        return 'List workflow runs';
    }

    public function description(): string
    {
        return 'Lists the runs of a project\'s workflows, newest first, optionally of one workflow and in some statuses (e.g. failed), of one environment (default production; "all" for every environment). workflow_run_status shows one run with its steps.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'workflow' => Schema::string('Only runs of this workflow.', ['maxLength' => 100]),
            'status' => Schema::array(Schema::enum(self::STATUSES, 'Run status.'), 'Only runs in these statuses.', 8),
            'search' => Schema::string('Run id, event id, idempotency key or text in the event.', ['maxLength' => 200]),
            'limit' => Schema::integer('At most this many runs (default 25).', 1, 100),
            'environment' => Schema::string('Environment name or id, or "all" (default: production, the project\'s own workload).', ['maxLength' => 64]),
        ], ['project']);
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

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'workflow-runs'), [
            'workflow' => $arguments['workflow'] ?? null,
            'status' => ! empty($arguments['status']) ? implode(',', (array) $arguments['status']) : null,
            'q' => $arguments['search'] ?? null,
            'per_page' => (int) ($arguments['limit'] ?? 25),
            'environment' => isset($arguments['environment']) && $arguments['environment'] !== '' ? (string) $arguments['environment'] : null,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the runs');
        }

        $runs = array_values(array_map([WorkflowRunStatus::class, 'runSummary'], array_filter((array) $result->get('data', []), 'is_array')));

        return ToolResult::ok([
            'runs' => $runs,
            'total' => $result->get('meta.total'),
        ], count($runs).' run(s).');
    }
}
