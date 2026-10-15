<?php

namespace App\Mcp\Tools\Catalog\Code;

use App\Mcp\Tools\Concerns\ReadsCode;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class CodeReadFile extends Tool
{
    use ReadsCode, ReadsProjects, WaitsForDeployments;

    public function name(): string
    {
        return 'code_read_file';
    }

    public function title(): string
    {
        return 'Read a code file';
    }

    public function description(): string
    {
        return 'Returns the content of one file of a project\'s code (newest version, or the given one). code_list_files names the files. Large files are cut at the server\'s limit (truncated true).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'path' => Schema::string('File name as code_list_files shows it.', ['minLength' => 1, 'maxLength' => 253]),
            'version' => Schema::string('Version number ("3", "v3") or id; default the newest.', ['maxLength' => 64]),
        ], ['project', 'path']);
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
        $path = (string) $arguments['path'];
        if (! array_key_exists($path, $code['files'])) {
            throw new ToolError("No file \"{$path}\". Files: ".(implode(', ', array_keys($code['files'])) ?: 'none').'.');
        }

        $content = $code['files'][$path];
        $max = max(1024, (int) config('mcp.tools.max_file_bytes', 512 * 1024));
        $bytes = strlen($content);
        $cut = $bytes > $max;
        if ($cut) {
            $content = mb_strcut($content, 0, $max);
        }

        return ToolResult::ok([
            'path' => $path,
            'bytes' => $bytes,
            'truncated' => $cut,
            'version' => $code['version']['number'] ?? null,
            'content' => $content,
        ], $path.' ('.$bytes.' bytes'.($cut ? ", first {$max} shown" : '').').');
    }
}
