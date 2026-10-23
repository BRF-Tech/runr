<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class EnvironmentDelete extends Tool
{
    public function name(): string
    {
        return 'environment_delete';
    }

    public function title(): string
    {
        return 'Delete an environment';
    }

    public function description(): string
    {
        return 'Deletes an environment of a project: its workload leaves its region, its custom domains and bindings are detached (the bindings stay), its deployments and secrets go. The production environment is the project itself and cannot be deleted here.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'environment' => Schema::string('Environment name or id.', ['maxLength' => 64]),
            'confirm' => Schema::confirm('deleting the environment with its workload, deployments and secrets'),
        ], ['project', 'environment', 'confirm']);
    }

    public function permissions(): array
    {
        return ['environments.manage'];
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
        $environment = (string) $arguments['environment'];

        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'environments', $environment));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Deleting the environment');
        }
        $context->setTarget('project', $projectId);

        return ToolResult::ok(['deleted' => $environment, 'incomplete' => (bool) $result->get('incomplete', false)], (string) ($result->get('message') ?? "Environment {$environment} deleted."));
    }
}
