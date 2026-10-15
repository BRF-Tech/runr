<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class SandboxArchiveDownload extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_archive_download';
    }

    public function title(): string
    {
        return 'Download a directory of a sandbox as tar';
    }

    public function description(): string
    {
        return 'Packs a directory of a running sandbox (default /workspace) into a tar archive and returns it base64-encoded (archive_base64), entries relative to the directory — to take a whole result tree out at once. Archives above the server limit (max_bytes in the error) are refused: pack a smaller directory, or read single files with sandbox_file_read.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'path' => Schema::string('Directory to pack (default /workspace).', ['minLength' => 1, 'maxLength' => 4096, 'pattern' => '^/']),
        ], ['sandbox']);
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
        $path = (string) ($arguments['path'] ?? '/workspace');
        $context->setTarget('sandbox', $id);

        $result = $this->sandboxCall($context, 'GET', self::sandboxPath($id, 'files', 'archive'), [], ['path' => $path], 'The directory was not packed');

        $max = self::maxArchiveBytes();
        $bytes = strlen($result->body);
        if ($bytes > $max) {
            throw new ToolError("The archive of {$path} is larger than {$max} bytes: pack a smaller directory or read single files with sandbox_file_read.", [
                'max_bytes' => $max,
                'path' => $path,
            ]);
        }

        return ToolResult::ok([
            'sandbox' => $id,
            'path' => $path,
            'format' => 'tar',
            'bytes' => $bytes,
            'sha256' => hash('sha256', $result->body),
            'archive_base64' => base64_encode($result->body),
        ], "Tar archive of {$path}: {$bytes} bytes.");
    }

    public static function maxArchiveBytes(): int
    {
        return max(1024, (int) config('mcp.tools.max_archive_bytes', 4 * 1024 * 1024));
    }
}
