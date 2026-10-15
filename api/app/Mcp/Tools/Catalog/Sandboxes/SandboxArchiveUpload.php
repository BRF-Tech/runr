<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class SandboxArchiveUpload extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_archive_upload';
    }

    public function title(): string
    {
        return 'Upload a tar archive into a sandbox';
    }

    public function description(): string
    {
        return 'Extracts a tar archive (archive_base64) into a directory of a running sandbox (default /workspace; created when needed) — to put a whole project tree in at once. Files of the same name are overwritten. The archive may be at most the server limit before encoding (max_bytes in the error); for a few files sandbox_file_write is simpler.';
    }

    public function inputSchema(): array
    {
        // base64 of the largest archive, with some room for line breaks some encoders add
        $maxLength = (int) (ceil(SandboxArchiveDownload::maxArchiveBytes() / 3) * 4) + 1024;

        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'archive_base64' => Schema::string('The tar archive, base64-encoded (plain tar; not gzip).', ['minLength' => 4, 'maxLength' => $maxLength]),
            'path' => Schema::string('Directory to extract into (default /workspace).', ['minLength' => 1, 'maxLength' => 4096, 'pattern' => '^/']),
        ], ['sandbox', 'archive_base64']);
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
        // Like sandbox_file_write: writes into the sandbox's own scratch file system
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $path = (string) ($arguments['path'] ?? '/workspace');
        $context->setTarget('sandbox', $id);

        $tar = base64_decode(preg_replace('/\s+/', '', (string) $arguments['archive_base64']), true);
        if ($tar === false || $tar === '') {
            throw new ToolError('archive_base64 is not valid base64.');
        }

        $max = SandboxArchiveDownload::maxArchiveBytes();
        if (strlen($tar) > $max) {
            throw new ToolError('The archive is larger than '.$max.' bytes: upload it in parts (several directories) or write single files with sandbox_file_write.', ['max_bytes' => $max]);
        }
        // A tar archive has the ustar magic at offset 257 (GNU and POSIX formats)
        if (strlen($tar) < 512 || ! str_starts_with(substr($tar, 257, 5), 'ustar')) {
            throw new ToolError('archive_base64 is not a tar archive (gzip-compressed archives are not accepted: send the plain tar).');
        }

        $result = $context->api()->raw('PUT', self::sandboxPath($id, 'files', 'archive'), $tar, 'application/x-tar', ['path' => $path]);
        if (! $result->ok()) {
            throw $this->sandboxError($result, 'The archive was not extracted');
        }

        $data = (array) $result->data();

        return ToolResult::ok(['sandbox' => $id, 'path' => $path, 'bytes' => strlen($tar)] + $data, 'Extracted '.strlen($tar)." bytes into {$path}.");
    }
}
