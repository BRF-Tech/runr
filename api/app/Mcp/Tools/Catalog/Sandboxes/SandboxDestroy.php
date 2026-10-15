<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxDestroy extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_destroy';
    }

    public function title(): string
    {
        return 'Remove a sandbox';
    }

    public function description(): string
    {
        return 'Removes a sandbox: its pod, files, processes, environment Secret and network policy are gone. Copy out what you need first (sandbox_file_read). Idempotent; needs confirm: true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'confirm' => Schema::confirm('removing the sandbox and everything in it'),
        ], ['sandbox', 'confirm']);
    }

    public function permissions(): array
    {
        return ['sandboxes.create'];
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

        $sandbox = self::sandboxSummary((array) $this->sandboxCall($context, 'DELETE', self::sandboxPath($id), [], [], 'The sandbox was not removed')->data());

        return ToolResult::ok(['sandbox' => $sandbox], 'Sandbox '.$id.' is '.($sandbox['status'] ?? 'removed').'.');
    }
}
