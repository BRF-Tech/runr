<?php

namespace App\Mcp\Tools\Catalog\Code;

use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class VersionsList extends Tool
{
    use WaitsForDeployments;

    public function name(): string
    {
        return 'versions_list';
    }

    public function title(): string
    {
        return 'List versions';
    }

    public function description(): string
    {
        return 'Lists a project\'s versions, newest first: number, message, changed files and build status. Every code or config change is a version; deploy and rollback take a version number.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'limit' => Schema::integer('At most this many versions (default 20).', 1, 200),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'code';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $versions = array_slice($this->versions($context, $projectId), 0, (int) ($arguments['limit'] ?? 20));

        return ToolResult::ok(
            ['versions' => array_map([self::class, 'versionSummary'], $versions)],
            count($versions).' version(s)'.($versions ? ', newest '.($versions[0]['label'] ?? $versions[0]['number'] ?? '') : '').'.',
        );
    }
}
