<?php

namespace App\Mcp\Tools\Catalog\Projects;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ProjectDelete extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'project_delete';
    }

    public function title(): string
    {
        return 'Delete a project';
    }

    public function description(): string
    {
        return 'Deletes a project with all its versions, deployments, custom domains and workloads in the region. This cannot be undone. Ask the user first; pass the project\'s exact name (or slug) as confirm_name.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'confirm_name' => Schema::string('The exact name or slug of the project, typed out to confirm the deletion.', ['minLength' => 1, 'maxLength' => 255]),
        ], ['project', 'confirm_name']);
    }

    public function permissions(): array
    {
        return ['projects.delete'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $project = $this->project($context, $context->projectId((string) $arguments['project']));
        $confirm = (string) $arguments['confirm_name'];

        if ($confirm !== (string) ($project['name'] ?? '') && $confirm !== (string) ($project['slug'] ?? '')) {
            throw new ToolError(sprintf('confirm_name "%s" does not match the project (name "%s", slug "%s"). Nothing was deleted.', $confirm, $project['name'] ?? '', $project['slug'] ?? ''));
        }

        $result = $context->api()->delete(InternalApi::path('projects', (string) $project['id']));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The project was not deleted');
        }

        return ToolResult::ok(['deleted' => ['id' => $project['id'], 'name' => $project['name'] ?? null]], "Project {$project['name']} deleted.");
    }
}
