<?php

namespace App\Mcp\Tools\Catalog\Workflows;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WorkflowRunStatus extends Tool
{
    public function name(): string
    {
        return 'workflow_run_status';
    }

    public function title(): string
    {
        return 'Workflow run status';
    }

    public function description(): string
    {
        return 'Shows one workflow run: status, output or error, and its steps (name, type, status, attempts, error, sleep / wait details).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'run' => Schema::string('Run id (workflow_run_start, workflow_runs_list).', ['minLength' => 1, 'maxLength' => 64]),
        ], ['project', 'run']);
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

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'workflow-runs', (string) $arguments['run']));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the run');
        }

        $run = (array) $result->get('data', []);
        $steps = array_values(array_map(fn (array $s) => array_filter([
            'name' => $s['name'] ?? $s['step_key'] ?? null,
            'type' => $s['type'] ?? null,
            'status' => $s['status'] ?? null,
            'attempt' => $s['attempt'] ?? null,
            'error' => $s['error'] ?? null,
            'wake_at' => $s['wake_at'] ?? null,
            'wait_event' => $s['wait_event'] ?? null,
            'duration_ms' => $s['duration_ms'] ?? null,
        ], fn ($v) => $v !== null && $v !== []), array_filter((array) ($run['steps'] ?? []), 'is_array')));

        $details = self::runSummary($run) + array_filter([
            'output' => $run['output'] ?? null,
            'steps' => $steps,
        ], fn ($v) => $v !== null && $v !== []);

        return ToolResult::ok(['run' => $details], 'Run '.($run['id'] ?? '?').' is '.($run['status'] ?? 'unknown').' ('.count($steps).' step(s)).');
    }

    public static function runSummary(array $run): array
    {
        return array_filter([
            'id' => $run['id'] ?? null,
            'workflow' => $run['workflow']['name'] ?? null,
            'status' => $run['status'] ?? null,
            'source' => $run['trigger_source'] ?? null,
            'event' => $run['event_name'] ?? null,
            'error' => $run['error'] ?? null,
            'attempt' => $run['attempt'] ?? null,
            'idempotency_key' => $run['idempotency_key'] ?? null,
            'started_at' => $run['started_at'] ?? null,
            'ended_at' => $run['ended_at'] ?? null,
            'duration_ms' => $run['duration_ms'] ?? null,
            'created_at' => $run['created_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
