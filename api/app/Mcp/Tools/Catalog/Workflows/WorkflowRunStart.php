<?php

namespace App\Mcp\Tools\Catalog\Workflows;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WorkflowRunStart extends Tool
{
    public function name(): string
    {
        return 'workflow_run_start';
    }

    public function title(): string
    {
        return 'Start a workflow run';
    }

    public function description(): string
    {
        return 'Starts a run of a durable workflow with an input object (the event data the workflow reads). With an idempotency key a repeated call returns the existing run instead of starting another. environment runs the workflow of that environment (default production). Follow it with workflow_run_status.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'workflow' => Schema::string('Workflow name (workflows_list).', ['minLength' => 1, 'maxLength' => 100]),
            'data' => ['type' => 'object', 'description' => 'Input of the run (JSON object).'],
            'idempotency_key' => Schema::string('Starts at most one run per key.', ['maxLength' => 200]),
            'test' => Schema::boolean('Mark the run as a test run.'),
            'environment' => Schema::string('Environment name or id (default: production, the project\'s own workload).', ['maxLength' => 64]),
        ], ['project', 'workflow']);
    }

    public function permissions(): array
    {
        return ['workflows.run'];
    }

    public function group(): string
    {
        return 'workflows';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $workflow = (string) $arguments['workflow'];

        $payload = ['data' => (object) ((array) ($arguments['data'] ?? []))];
        if (! empty($arguments['idempotency_key'])) {
            $payload['idempotency_key'] = (string) $arguments['idempotency_key'];
        }
        if (! empty($arguments['test'])) {
            $payload['test'] = true;
        }
        if (isset($arguments['environment']) && $arguments['environment'] !== '') {
            $payload['environment'] = (string) $arguments['environment'];
        }

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'workflows', $workflow, 'runs'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, "Workflow {$workflow} did not start");
        }

        $run = (array) $result->get('data', []);
        $created = (bool) $result->get('created', true);

        return ToolResult::ok([
            'run' => WorkflowRunStatus::runSummary($run),
            'created' => $created,
        ], $created ? 'Run '.($run['id'] ?? '?').' started ('.($run['status'] ?? 'queued').').' : 'A run with this idempotency key exists: '.($run['id'] ?? '?').'.');
    }
}
