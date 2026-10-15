<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxFileRead extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_file_read';
    }

    public function title(): string
    {
        return 'Read a file in a sandbox';
    }

    public function description(): string
    {
        return 'Reads one file of a sandbox: UTF-8 text as content, anything else (images of /output, archives) as content_base64. Large files are cut at the tool output limit (truncated: true).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'path' => Schema::string('Absolute file path, e.g. /workspace/report.csv or /output/chart.png.', ['minLength' => 2, 'maxLength' => 4096, 'pattern' => '^/']),
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
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $context->setTarget('sandbox', $id);

        $file = (array) $this->sandboxCall($context, 'GET', self::sandboxPath($id, 'files', 'content'), [], ['path' => $arguments['path'], 'format' => 'json'], 'Reading the file')->data();
        $bytes = base64_decode((string) ($file['content_base64'] ?? ''), true);
        $bytes = $bytes === false ? '' : $bytes;

        $size = strlen($bytes);
        $max = max(1024, (int) config('mcp.tools.max_file_bytes', 512 * 1024));
        $truncated = $size > $max;
        $text = mb_check_encoding($bytes, 'UTF-8');

        $result = ['path' => $file['path'] ?? $arguments['path'], 'size' => (int) ($file['size'] ?? $size)];
        if ($text) {
            // mb_strcut never splits a character
            $result['content'] = $truncated ? mb_strcut($bytes, 0, $max, 'UTF-8') : $bytes;
        } else {
            $result['content_base64'] = base64_encode($truncated ? substr($bytes, 0, $max) : $bytes);
        }
        if ($truncated) {
            $result['truncated'] = true;
        }

        return ToolResult::ok($result, $result['path'].' ('.$result['size'].' bytes'.($truncated ? ', the first '.$max.' shown' : '').($text ? '' : ', binary as base64').').');
    }
}
