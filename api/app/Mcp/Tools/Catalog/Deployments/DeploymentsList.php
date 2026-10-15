<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Tools\Concerns\ReadsDeployments;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class DeploymentsList extends Tool
{
    use ReadsDeployments, WaitsForDeployments;

    public function name(): string
    {
        return 'deployments_list';
    }

    public function title(): string
    {
        return 'List deployments';
    }

    public function description(): string
    {
        return 'Lists a project\'s deployments, newest first: status (pending, running, active, shutting_down, shut_down, failed), version, who deployed and when it became ready.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'limit' => Schema::integer('At most this many deployments (default 20).', 1, 200),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $deployments = array_slice($this->deployments($context, $projectId), 0, (int) ($arguments['limit'] ?? 20));

        return ToolResult::ok(
            ['deployments' => array_map([self::class, 'deploymentSummary'], $deployments)],
            count($deployments).' deployment(s).',
        );
    }
}
