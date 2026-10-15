<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxGet extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_get';
    }

    public function title(): string
    {
        return 'Get a sandbox';
    }

    public function description(): string
    {
        return 'One sandbox: status (pending, running, paused, terminating, terminated, failed), region, runtime or image, isolation class, CPU / memory / disk, network (none or an egress allowlist), the names of its environment variables, metadata, when it expires (TTL and idle timeout) and why it ended. Call it to follow a sandbox that is still starting.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['sandbox' => self::sandboxArgument()], ['sandbox']);
    }

    public function permissions(): array
    {
        return ['sandboxes.view'];
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
        $sandbox = (array) $this->sandboxCall($context, 'GET', self::sandboxPath($id), [], [], 'Reading the sandbox')->data();
        $context->setTarget('sandbox', $id);

        $summary = self::sandboxSummary($sandbox);

        return ToolResult::ok(['sandbox' => $summary], 'Sandbox '.$id.' is '.($summary['status'] ?? 'unknown').'.');
    }
}
