<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxProcessStop extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_process_stop';
    }

    public function title(): string
    {
        return 'Stop a background process';
    }

    public function description(): string
    {
        return 'Sends a signal to a background process of a sandbox and its process group: TERM (default) to stop it, KILL to end it at once, INT, HUP, QUIT, USR1, USR2, or STOP / CONT to pause and continue it. Returns its state afterwards.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'process' => Schema::string('The process id (from sandbox_process_start).', ['minLength' => 1, 'maxLength' => 64, 'pattern' => '^[0-9A-Za-z]+$']),
            'signal' => Schema::enum(['TERM', 'KILL', 'INT', 'HUP', 'QUIT', 'USR1', 'USR2', 'STOP', 'CONT'], 'The signal (default TERM).', 'TERM'),
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $signal = (string) ($arguments['signal'] ?? 'TERM');
        $context->setTarget('sandbox', $id);

        $uri = self::sandboxPath($id, 'processes', (string) $arguments['process']).'?'.http_build_query(['signal' => $signal]);
        $process = (array) $this->sandboxCall($context, 'DELETE', $uri, [], [], 'The process was not signalled')->data();

        return ToolResult::ok(['process' => $process], "Sent {$signal}; the process is ".($process['status'] ?? 'unknown').'.');
    }
}
