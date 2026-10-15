<?php

namespace App\Mcp\Tools\Catalog\Projects;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ProjectsList extends Tool
{
    public function name(): string
    {
        return 'projects_list';
    }

    public function title(): string
    {
        return 'List projects';
    }

    public function description(): string
    {
        return 'Lists the projects you can see: id, name, slug, type (xaas single-file function, faas multi-file project, caas container image), runtime, region, trigger, URL and the status of the latest deployment. Filter by a search term, type, runtime or region.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'search' => Schema::string('Part of the name or slug.', ['maxLength' => 255]),
            'service_type' => Schema::enum(['xaas', 'faas', 'caas'], 'Only projects of this type.'),
            'runtime' => Schema::string('Only projects on this runtime id (python, nodejs, go, php, …).', ['maxLength' => 32]),
            'region' => Schema::string('Only projects in this region (region id).', ['maxLength' => 64]),
            'limit' => Schema::integer('At most this many projects (default 100).', 1, 500),
        ]);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/projects', [
            'search' => $arguments['search'] ?? null,
            'service_type' => $arguments['service_type'] ?? null,
            'runtime' => $arguments['runtime'] ?? null,
            'knative_region_id' => $arguments['region'] ?? null,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result);
        }

        $limit = (int) ($arguments['limit'] ?? 100);
        $projects = array_slice(array_values(array_filter((array) $result->data(), 'is_array')), 0, $limit);

        return ToolResult::ok(
            ['projects' => array_map([self::class, 'summary'], $projects), 'count' => count($projects)],
            count($projects).' project(s).',
        );
    }

    /** The fields of a project an agent needs (ProjectResource is much larger). */
    public static function summary(array $project): array
    {
        $config = is_array($project['config'] ?? null) ? $project['config'] : [];

        return array_filter([
            'id' => $project['id'] ?? null,
            'name' => $project['name'] ?? null,
            'slug' => $project['slug'] ?? null,
            'service_type' => $project['service_type'] ?? null,
            'runtime' => $project['runtime'] ?? null,
            'runtime_version' => $project['runtime_version'] ?? null,
            'region' => $project['region']['name'] ?? $project['knative_region_id'] ?? null,
            'region_id' => $project['knative_region_id'] ?? null,
            'trigger' => $config['trigger_type'] ?? null,
            'url' => $project['endpoint_url'] ?? null,
            'latest_version' => $project['latest_version']['number'] ?? null,
            'deployment_status' => $project['latest_deployment']['status'] ?? null,
            'mcp_server' => $project['mcp_server'] ?? null,
            'description' => $project['description'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
