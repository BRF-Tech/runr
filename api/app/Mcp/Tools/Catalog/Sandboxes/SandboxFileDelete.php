<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxFileDelete extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_file_delete';
    }

    public function title(): string
    {
        return 'Delete a file in a sandbox';
    }

    public function description(): string
    {
        return 'Deletes a file or an empty directory of a sandbox — or, with recursive true, a whole directory tree. Needs confirm: true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'path' => Schema::string('Absolute path of the file or directory.', ['minLength' => 2, 'maxLength' => 4096, 'pattern' => '^/']),
            'recursive' => Schema::boolean('Delete a directory with everything in it (default false).', false),
            'confirm' => Schema::confirm('the deletion'),
        ], ['sandbox', 'path', 'confirm']);
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $context->setTarget('sandbox', $id);

        $deleted = (array) $this->sandboxCall($context, 'DELETE', self::sandboxPath($id, 'files'), [], array_filter([
            'path' => $arguments['path'],
            'recursive' => ! empty($arguments['recursive']) ? 1 : null,
        ]), 'The path was not deleted')->data();

        return ToolResult::ok($deleted, 'Deleted '.($deleted['path'] ?? $arguments['path']).'.');
    }
}
