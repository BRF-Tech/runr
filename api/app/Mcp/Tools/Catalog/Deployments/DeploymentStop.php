<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class DeploymentStop extends Tool
{
    use ReadsDeployments;

    public function name(): string
    {
        return 'deployment_stop';
    }

    public function title(): string
    {
        return 'Stop a deployment';
    }

    public function description(): string
    {
        return 'Stops and deletes a deployment (default: the current one). A live deployment is torn down in the region; the project stops serving until it is deployed again. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'deployment' => Schema::string('Deployment id; default the current deployment.', ['maxLength' => 64]),
            'confirm' => Schema::confirm('stopping the deployment'),
        ], ['project', 'confirm']);
    }

    public function permissions(): array
    {
        return ['projects.deploy'];
    }

    public function group(): string
    {
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $deployment = $this->deployment($context, $projectId, $arguments['deployment'] ?? null);

        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'deployments', (string) $deployment['id']));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The deployment was not stopped');
        }

        return ToolResult::ok(
            ['stopped' => ['id' => $deployment['id'], 'status_before' => $deployment['status'] ?? null]],
            'Deployment '.$deployment['id'].' stopped and deleted.',
        );
    }
}
