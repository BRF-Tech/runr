<?php

namespace App\Mcp\Tools\Catalog\Projects;

use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class ProjectGet extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'project_get';
    }

    public function title(): string
    {
        return 'Show a project';
    }

    public function description(): string
    {
        return 'Shows one project: type, runtime, region, URL, trigger, size and scaling (with the timed warmer\'s schedule and the warm container count it last applied), the names of its environment variables (values with env_list), its latest version and deployment status, its runtime patch state (runtime_patches_get has the details) and whether it is a hosted MCP server.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()], ['project']);
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
        $project = $this->project($context, $context->projectId((string) $arguments['project']));
        $config = self::config($project);

        $env = $config['env_vars'] ?? null;
        $details = ProjectsList::summary($project) + self::compact([
            'config' => self::compact([
                'image' => $config['image'] ?? null,
                'command' => $config['command'] ?? null,
                'ports' => $config['ports'] ?? null,
                'handler' => $config['handler'] ?? null,
                'filename' => $config['filename'] ?? null,
                'cpu' => $config['cpu'] ?? null,
                'memory_mb' => $config['memory_mb'] ?? null,
                'timeout_seconds' => $config['timeout_seconds'] ?? null,
                'schedule' => $config['schedule'] ?? null,
                'queue_stream' => $config['queue_stream'] ?? null,
                'scale_to_zero' => $config['scale_to_zero'] ?? null,
                'min_scale' => $config['min_scale'] ?? null,
                'max_scale' => $config['max_scale'] ?? null,
                // Timed warmer: [{cron, min_scale}] in the organization's time zone
                'scale_schedule' => $config['scale_schedule'] ?? null,
                'gpu' => $config['gpu'] ?? null,
            ]),
            // What the timed warmer last applied to the running workload: {applied, applied_at}
            'warm_scale' => $project['warm_scale'] ?? null,
            'env_names' => is_array($env) ? array_values(array_map('strval', array_keys($env))) : null,
            'env_hidden' => ! empty($config['env_vars_hidden']) ? true : null,
            'secret_env_names' => is_array($config['secret_env_keys'] ?? null) ? $config['secret_env_keys'] : null,
            'endpoint_type' => $project['endpoint_type'] ?? null,
            'gateway_route' => $project['gateway_route'] ?? null,
            'runtime_eol' => $project['runtime_eol'] ?? null,
            // up_to_date | patch_available | pinned | overdue, level, deadline, current / latest tag (runtime_patches_get has the details)
            'runtime_patch' => is_array($project['runtime_patch'] ?? null) ? array_filter([
                'state' => $project['runtime_patch']['state'] ?? null,
                'level' => $project['runtime_patch']['level'] ?? null,
                'deadline_at' => $project['runtime_patch']['deadline_at'] ?? null,
                'current' => $project['runtime_patch']['current']['tag'] ?? null,
                'latest' => $project['runtime_patch']['latest']['tag'] ?? null,
                'pinned' => $project['runtime_patch']['pin']['tag'] ?? $project['runtime_patch']['pin']['id'] ?? null,
            ], fn ($v) => $v !== null) : null,
            'created_by' => $project['created_by']['name'] ?? null,
            'created_at' => $project['created_at'] ?? null,
        ]);

        return ToolResult::ok($details, sprintf('%s (%s, %s).', $project['name'] ?? '?', $project['service_type'] ?? '?', $details['deployment_status'] ?? 'not deployed'));
    }
}
