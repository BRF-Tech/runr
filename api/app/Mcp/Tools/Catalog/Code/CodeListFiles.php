<?php

namespace App\Mcp\Tools\Catalog\Code;

use App\Mcp\Tools\Concerns\ReadsCode;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class CodeListFiles extends Tool
{
    use ReadsCode, ReadsProjects, WaitsForDeployments;

    public function name(): string
    {
        return 'code_list_files';
    }

    public function title(): string
    {
        return 'List code files';
    }

    public function description(): string
    {
        return 'Lists the files of a project\'s code (newest version, or the given one) with their sizes. A project without any version lists its runtime\'s starter files (is_default true). Read a file with code_read_file.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'version' => Schema::string('Version number ("3", "v3") or id; default the newest.', ['maxLength' => 64]),
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
        $project = $this->project($context, $context->projectId((string) $arguments['project']));
        $versionId = ! empty($arguments['version'])
            ? (string) $this->resolveVersion($context, (string) $project['id'], (string) $arguments['version'])['id']
            : null;

        $code = $this->codeFiles($context, $project, $versionId);
        $files = [];
        foreach ($code['files'] as $name => $content) {
            $files[] = ['path' => (string) $name, 'bytes' => strlen($content)];
        }
        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return ToolResult::ok(self::compact([
            'files' => $files,
            'version' => $code['version'] ? self::versionSummary($code['version']) : null,
            'is_default' => $code['is_default'] ?: null,
        ]), count($files).' file(s)'.($code['is_default'] ? ' (starter files, nothing saved yet)' : '').'.');
    }
}
