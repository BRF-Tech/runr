<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsUptime;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class UptimeCheckRun extends Tool
{
    use ReadsUptime;

    public function name(): string
    {
        return 'uptime_check_run';
    }

    public function title(): string
    {
        return 'Run an uptime check now';
    }

    public function description(): string
    {
        return 'Probes an uptime check once now from the RunR API and records the result like a scheduled probe (it can open or resolve an incident): whether it passed, the status code, the response time and the error.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'check' => Schema::string('The check: its id or exact name.', ['minLength' => 1, 'maxLength' => 100]),
        ], ['project', 'check']);
    }

    public function permissions(): array
    {
        return ['uptime.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $check = $this->findUptimeCheck($context, $projectId, (string) $arguments['check']);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'uptime-checks', (string) $check['id'], 'run'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The check did not run');
        }

        $data = (array) $result->data();
        $out = array_filter([
            'ok' => (bool) ($data['ok'] ?? false),
            'status_code' => $data['status_code'] ?? null,
            'response_ms' => $data['response_ms'] ?? null,
            'error' => $data['error'] ?? null,
            'check' => isset($data['check']) ? self::uptimeSummary((array) $data['check']) : null,
        ], fn ($v) => $v !== null);

        return ToolResult::ok($out, ($out['ok'] ? 'Passed' : 'Failed').(isset($out['status_code']) ? " (HTTP {$out['status_code']})" : '').(isset($out['error']) ? ': '.$out['error'] : '.'));
    }
}
