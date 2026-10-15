<?php

namespace App\Mcp\Tools\Catalog\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class McpServerCalls extends Tool
{
    public function name(): string
    {
        return 'mcp_server_calls';
    }

    public function title(): string
    {
        return 'Calls of a hosted MCP server';
    }

    public function description(): string
    {
        return 'The gateway\'s call log of a hosted MCP server, newest first: JSON-RPC method, tool / resource / prompt name, HTTP status, outcome (ok, error, denied, unreachable), duration and caller. Use it to see whether clients reach the server and which calls fail.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'outcome' => Schema::enum(['ok', 'error', 'denied', 'unreachable'], 'Only calls with this outcome.'),
            'method' => Schema::string('Only this JSON-RPC method, e.g. "tools/call".', ['maxLength' => 100]),
            'limit' => Schema::integer('Calls (default 25).', 1, 100),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['logs.view'];
    }

    public function group(): string
    {
        return 'mcp';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'mcp-server', 'calls'), [
            'outcome' => $arguments['outcome'] ?? null,
            'method' => $arguments['method'] ?? null,
            'per_page' => (int) ($arguments['limit'] ?? 25),
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the call log');
        }

        $calls = array_values(array_map(fn (array $c) => array_filter([
            'occurred_at' => $c['occurred_at'] ?? null,
            'method' => $c['method'] ?? null,
            'name' => $c['name'] ?? null,
            'status_code' => $c['status_code'] ?? null,
            'outcome' => $c['outcome'] ?? null,
            'error' => $c['error'] ?? null,
            'duration_ms' => $c['duration_ms'] ?? null,
            'caller' => $c['actor']['name'] ?? $c['actor']['type'] ?? null,
            'auth' => $c['auth'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''), array_filter((array) $result->get('data', []), 'is_array')));

        return ToolResult::ok([
            'calls' => $calls,
            'total' => $result->get('meta.total'),
        ], count($calls).' call(s).');
    }
}
