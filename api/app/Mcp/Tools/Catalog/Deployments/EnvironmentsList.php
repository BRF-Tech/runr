<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class EnvironmentsList extends Tool
{
    public function name(): string
    {
        return 'environments_list';
    }

    public function title(): string
    {
        return 'List environments';
    }

    public function description(): string
    {
        return 'Lists a project\'s environments in pipeline order (dev → staging → production; "production" is the project\'s own workload): region, URL, the version each runs, whether promotions into it need approval and the git branch that deploys it.';
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
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'environments'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Listing the environments');
        }

        $environments = array_map(fn (array $e) => self::summary($e), (array) $result->data());

        return ToolResult::ok(['environments' => $environments], count($environments).' environment(s): '.implode(' → ', array_column($environments, 'name')).'.');
    }

    /** @return array<string, mixed> */
    public static function summary(array $environment): array
    {
        return [
            'id' => $environment['id'] ?? null,
            'name' => $environment['name'] ?? null,
            'is_primary' => (bool) ($environment['is_primary'] ?? false),
            'region' => $environment['region']['name'] ?? null,
            'url' => $environment['url'] ?? null,
            'requires_approval' => (bool) ($environment['requires_approval'] ?? false),
            'git_branch' => $environment['git_branch'] ?? null,
            'version' => $environment['deployment']['version']['number'] ?? null,
            'status' => $environment['deployment']['status'] ?? null,
        ];
    }
}
