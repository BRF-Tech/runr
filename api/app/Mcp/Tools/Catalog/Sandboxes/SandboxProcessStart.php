<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxProcessStart extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_process_start';
    }

    public function title(): string
    {
        return 'Start a background process in a sandbox';
    }

    public function description(): string
    {
        return 'Starts a detached background process in a running sandbox — a dev server, a watcher, a long training run — and returns at once with its process id. It keeps running after this call; sandbox_process_logs reads its output, sandbox_processes_list shows its state, sandbox_process_stop signals it. Use sandbox_exec for commands you want the result of.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'cmd' => self::commandArgument('The command: a string run by /bin/sh -c, or an argv list.'),
            'env' => self::envArgument('Extra environment variables of the process.'),
            'cwd' => Schema::string('Working directory (default /workspace).', ['minLength' => 1, 'maxLength' => 4096, 'pattern' => '^/']),
        ], ['sandbox', 'cmd']);
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
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $context->setTarget('sandbox', $id);

        $process = (array) $this->sandboxCall($context, 'POST', self::sandboxPath($id, 'processes'), array_filter([
            'cmd' => $arguments['cmd'],
            'env' => ! empty($arguments['env']) ? (object) $arguments['env'] : null,
            'cwd' => $arguments['cwd'] ?? null,
        ], fn ($v) => $v !== null), [], 'The process did not start')->data();

        return ToolResult::ok(['process' => $process], sprintf('Process %s (pid %s) is %s.', $process['id'] ?? '?', $process['pid'] ?? '?', $process['status'] ?? 'running'));
    }
}
