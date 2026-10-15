<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxFilesList extends Tool
{
    use UsesSandboxes;

    /** Entries returned at most. */
    private const MAX_ENTRIES = 500;

    public function name(): string
    {
        return 'sandbox_files_list';
    }

    public function title(): string
    {
        return 'List files in a sandbox';
    }

    public function description(): string
    {
        return 'Lists a directory of a sandbox (default /workspace): name, path, type (file, dir, symlink), size, mode, modification time; directories first. A file path describes that file.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'path' => Schema::string('Absolute directory or file path (default /workspace).', ['minLength' => 1, 'maxLength' => 4096, 'pattern' => '^/']),
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
        $context->setTarget('sandbox', $id);

        $listing = (array) $this->sandboxCall($context, 'GET', self::sandboxPath($id, 'files'), [], ['path' => $arguments['path'] ?? '/workspace'], 'Listing the files')->data();
        $entries = array_values(array_filter((array) ($listing['entries'] ?? []), 'is_array'));

        $result = [
            'path' => $listing['path'] ?? ($arguments['path'] ?? '/workspace'),
            'is_dir' => (bool) ($listing['is_dir'] ?? true),
            'entries' => array_slice($entries, 0, self::MAX_ENTRIES),
            'truncated' => ! empty($listing['truncated']) || count($entries) > self::MAX_ENTRIES,
        ];

        return ToolResult::ok($result, count($entries).' entr'.(count($entries) === 1 ? 'y' : 'ies').' in '.$result['path'].'.');
    }
}
