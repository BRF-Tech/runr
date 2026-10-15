<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxExec extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_exec';
    }

    public function title(): string
    {
        return 'Run a command in a sandbox';
    }

    public function description(): string
    {
        return 'Runs one command in a running sandbox and returns its exit code, stdout and stderr (binary output as *_base64). cmd is a shell string ("pip install -r requirements.txt && pytest -q") or an argv list (["python3", "main.py"]); it runs in /workspace unless cwd says otherwise, with the sandbox\'s environment plus env, and is killed at timeout seconds (default 60, exit code 137, timed_out true). A non-zero exit code is a normal result, not an error. For code snippets prefer sandbox_run_code; for servers and long jobs sandbox_process_start.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'cmd' => self::commandArgument('The command: a string run by /bin/sh -c, or an argv list.'),
            'stdin' => Schema::string('Standard input (text).', ['maxLength' => 10485760]),
            'timeout' => Schema::integer('Seconds before the command is killed (default 60).', 1, 3600),
            'env' => self::envArgument('Extra environment variables of this command.'),
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
        // What the command does inside the sandbox is open; it may reach the hosts of its allowlist
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $context->setTarget('sandbox', $id);

        $body = array_filter([
            'cmd' => $arguments['cmd'],
            'stdin' => $arguments['stdin'] ?? null,
            'timeout' => $arguments['timeout'] ?? null,
            'env' => ! empty($arguments['env']) ? (object) $arguments['env'] : null,
            'cwd' => $arguments['cwd'] ?? null,
        ], fn ($v) => $v !== null);

        $result = (array) $this->sandboxCall($context, 'POST', self::sandboxPath($id, 'exec'), $body, [], 'The command did not run')->data();
        $result = self::capOutput($result);

        $exit = (int) ($result['exit_code'] ?? -1);

        return ToolResult::ok($result, match (true) {
            ! empty($result['timed_out']) => "Killed after the timeout (exit {$exit}).",
            $exit === 0 => 'Exit 0 in '.($result['duration_ms'] ?? '?').' ms.',
            default => "Exited with {$exit}.",
        });
    }
}
