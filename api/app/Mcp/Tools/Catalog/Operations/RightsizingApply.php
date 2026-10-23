<?php

namespace App\Mcp\Tools\Catalog\Operations;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * rightsizing_apply: the project's open recommendation becomes its config (a new version, the same
 * checks as project_update); the next deploy runs the new size.
 */
class RightsizingApply extends Tool
{
    public function name(): string
    {
        return 'rightsizing_apply';
    }

    public function title(): string
    {
        return 'Apply a rightsizing recommendation';
    }

    public function description(): string
    {
        return 'Applies a project\'s open rightsizing recommendation (rightsizing_list): its recommended CPU and memory go into the project config as a new version, checked like project_update (region capacity, fixed allocation). The workload runs the new size after the next deploy (deploy tool).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.update'];
    }

    public function group(): string
    {
        return 'operations';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'rightsizing', 'apply'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The recommendation was not applied');
        }

        $recommendation = (array) $result->get('data', []);

        return ToolResult::ok([
            'recommendation' => RightsizingList::summary($recommendation),
            'version' => $result->get('version'),
            'deploy_required' => (bool) $result->get('deploy_required', true),
        ], sprintf(
            'Saved %d MiB / %dm CPU as %s. Deploy the project to run the new size.',
            $recommendation['recommended']['memory_mb'] ?? 0,
            $recommendation['recommended']['cpu_m'] ?? 0,
            (string) $result->get('version.label', 'a new version'),
        ));
    }
}
