<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsDeployments;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class DeploymentStatus extends Tool
{
    use ReadsDeployments, WaitsForDeployments;

    public function name(): string
    {
        return 'deployment_status';
    }

    public function title(): string
    {
        return 'Deployment status';
    }

    public function description(): string
    {
        return 'The status of a deployment — by id, or the project\'s current one (the newest active, else the newest pending or running, else the newest) — with the Knative readiness message and the project URL. When it failed, logs_tail shows why.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'deployment' => Schema::string('Deployment id; default the current deployment.', ['maxLength' => 64]),
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
        $deployment = $this->deployment($context, $projectId, $arguments['deployment'] ?? null);

        $project = $context->api()->get(InternalApi::path('projects', $projectId));
        $url = $project->ok() ? $project->get('endpoint_url') : null;

        return ToolResult::ok(
            ['deployment' => self::deploymentSummary($deployment), 'url' => $url],
            'Deployment '.($deployment['id'] ?? '?').' is '.($deployment['status'] ?? 'unknown').(! empty($deployment['knative_message']) ? ': '.$deployment['knative_message'] : '').'.',
        );
    }
}
