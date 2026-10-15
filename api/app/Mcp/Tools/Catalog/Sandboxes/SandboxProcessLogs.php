<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxProcessLogs extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_process_logs';
    }

    public function title(): string
    {
        return 'Read the output of a background process';
    }

    public function description(): string
    {
        return 'The latest stdout and stderr of a background process of a sandbox (the last tail_bytes of each, default 65536) and its state (running, exited with its code, killed).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'process' => Schema::string('The process id (from sandbox_process_start), not the OS pid.', ['minLength' => 1, 'maxLength' => 64, 'pattern' => '^[0-9A-Za-z]+$']),
            'tail_bytes' => Schema::integer('Bytes from the end of each stream (default 65536).', 1, 10485760),
        ], ['sandbox', 'process']);
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
        $process = (string) $arguments['process'];
        $context->setTarget('sandbox', $id);

        $state = (array) $this->sandboxCall($context, 'GET', self::sandboxPath($id, 'processes', $process), [], [], 'Reading the process')->data();
        $logs = (array) $this->sandboxCall($context, 'GET', self::sandboxPath($id, 'processes', $process, 'logs'), [], [
            'tail_bytes' => (int) ($arguments['tail_bytes'] ?? 65536),
        ], 'Reading the process output')->data();

        $result = self::capOutput($logs) + ['process' => $state];

        return ToolResult::ok($result, sprintf('Process %s is %s%s.', $process, $state['status'] ?? 'unknown', isset($state['exit_code']) ? ' (exit '.$state['exit_code'].')' : ''));
    }
}
