<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class SandboxLifecycle extends Tool
{
    use UsesSandboxes;

    /** action => API path segment */
    private const ACTIONS = [
        'keepalive' => 'keepalive',
        'set_timeout' => 'timeout',
        'pause' => 'pause',
        'resume' => 'resume',
    ];

    public function name(): string
    {
        return 'sandbox_lifecycle';
    }

    public function title(): string
    {
        return 'Keep a sandbox alive, extend, pause or resume it';
    }

    public function description(): string
    {
        return 'Changes how long a sandbox lives or whether it runs: keepalive resets the idle timer (call it while you think between commands longer than the idle timeout); set_timeout gives it ttl_seconds more from now (up to the region\'s maximum lifetime from its start); pause freezes every process (commands are refused until resume; the TTL keeps counting); resume thaws it. keepalive, pause and resume need sandboxes.exec, set_timeout sandboxes.create.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'action' => Schema::enum(array_keys(self::ACTIONS), 'What to do.'),
            'ttl_seconds' => Schema::integer('set_timeout: the new lifetime from now, in seconds.', 10, 86400),
        ], ['sandbox', 'action']);
    }

    public function permissions(): array
    {
        return ['sandboxes.exec|sandboxes.create'];
    }

    public function group(): string
    {
        return 'sandboxes';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $action = (string) $arguments['action'];
        $context->setTarget('sandbox', $id);

        $body = [];
        if ($action === 'set_timeout') {
            if (! isset($arguments['ttl_seconds'])) {
                throw new ToolError('set_timeout needs ttl_seconds.');
            }
            $body = ['ttl_seconds' => (int) $arguments['ttl_seconds']];
        }

        $sandbox = self::sandboxSummary((array) $this->sandboxCall($context, 'POST', self::sandboxPath($id, self::ACTIONS[$action]), $body, [], 'The sandbox was not changed')->data());

        return ToolResult::ok(['sandbox' => $sandbox], match ($action) {
            'keepalive' => 'Idle timer reset; idle until '.($sandbox['idle_expires_at'] ?? '?').'.',
            'set_timeout' => 'The sandbox now expires at '.($sandbox['expires_at'] ?? '?').'.',
            default => 'The sandbox is '.($sandbox['status'] ?? '?').'.',
        });
    }
}
