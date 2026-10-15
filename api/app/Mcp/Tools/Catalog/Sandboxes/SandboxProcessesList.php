<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxProcessesList extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_processes_list';
    }

    public function title(): string
    {
        return 'List background processes of a sandbox';
    }

    public function description(): string
    {
        return 'The background processes of a sandbox (started with sandbox_process_start) with their state read from the sandbox: running, exited (with the exit code) or killed.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['sandbox' => self::sandboxArgument()], ['sandbox']);
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

        $processes = array_values(array_filter((array) $this->sandboxCall($context, 'GET', self::sandboxPath($id, 'processes'), [], [], 'Listing the processes')->data(), 'is_array'));
        $running = count(array_filter($processes, fn ($p) => ($p['status'] ?? null) === 'running'));

        return ToolResult::ok(['processes' => $processes], count($processes)." process(es), {$running} running.");
    }
}
