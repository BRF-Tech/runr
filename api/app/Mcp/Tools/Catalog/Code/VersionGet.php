<?php

namespace App\Mcp\Tools\Catalog\Code;

use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class VersionGet extends Tool
{
    use WaitsForDeployments;

    public function name(): string
    {
        return 'version_get';
    }

    public function title(): string
    {
        return 'Show a version';
    }

    public function description(): string
    {
        return 'Shows one version of a project: number, commit, message, changed files, build status and — when the build failed — its error. Default: the newest version.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'version' => Schema::string('Version number ("3", "v3"), id or "latest" (default).', ['maxLength' => 64]),
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
        $version = $this->resolveVersion($context, $projectId, $arguments['version'] ?? null);

        $details = self::versionSummary($version) + array_filter([
            'commit_id' => $version['commit_id'] ?? null,
            'created_by' => $version['created_by']['name'] ?? null,
        ]);

        return ToolResult::ok(['version' => $details], 'Version '.($version['number'] ?? '?').': '.($version['build_status'] ?? 'ready').'.');
    }
}
