<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class SandboxFileWrite extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_file_write';
    }

    public function title(): string
    {
        return 'Write a file in a sandbox';
    }

    public function description(): string
    {
        return 'Writes one file in a sandbox (replacing it), creating its parent directories: text as content or bytes as content_base64, with an optional mode ("0755" for scripts). Writable places: /workspace, /output, /tmp, /home/sandbox (the root file system is read-only by default).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'path' => Schema::string('Absolute file path, e.g. /workspace/main.py.', ['minLength' => 2, 'maxLength' => 4096, 'pattern' => '^/']),
            'content' => Schema::string('Text content (UTF-8).', ['maxLength' => 10485760]),
            'content_base64' => Schema::string('Binary content, base64 (instead of content).', ['maxLength' => 14000000]),
            'mode' => Schema::string('File mode, e.g. "0644" or "0755".', ['pattern' => '^0?[0-7]{3}$']),
        ], ['sandbox', 'path']);
    }

    public function permissions(): array
    {
        return ['sandboxes.exec'];
    }

    public function group(): string
    {
        return 'sandboxes';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (isset($arguments['content'], $arguments['content_base64'])) {
            throw new ToolError('Give content or content_base64, not both.');
        }
        if (! isset($arguments['content']) && ! isset($arguments['content_base64'])) {
            throw new ToolError('Give the file content: content (text) or content_base64 (bytes); an empty file is content "".');
        }

        $id = (string) $arguments['sandbox'];
        $context->setTarget('sandbox', $id);

        $body = array_filter([
            'content_base64' => isset($arguments['content']) ? base64_encode((string) $arguments['content']) : (string) $arguments['content_base64'],
            'mode' => $arguments['mode'] ?? null,
        ], fn ($v) => $v !== null);

        $uri = self::sandboxPath($id, 'files', 'content').'?'.http_build_query(['path' => $arguments['path']]);
        $written = (array) $this->sandboxCall($context, 'PUT', $uri, $body, [], 'The file was not written')->data();

        return ToolResult::ok($written, 'Wrote '.($written['path'] ?? $arguments['path']).' ('.($written['size'] ?? '?').' bytes).');
    }
}
