<?php

namespace App\Mcp\Tools\Catalog\Audit;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class AuditSearch extends Tool
{
    public function name(): string
    {
        return 'audit_search';
    }

    public function title(): string
    {
        return 'Search the audit log';
    }

    public function description(): string
    {
        return 'Searches the organization\'s audit log, newest first: who did what to which target, with the result (success, denied, failure). Filter by actor, action ("project.*" for a prefix, comma-separated list), target, result and time. MCP tool calls appear as mcp.tool.call.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'actor' => Schema::string('User id, or part of the name or e-mail.', ['maxLength' => 255]),
            'action' => Schema::string('Action, prefix ("deployment.*") or comma-separated list.', ['maxLength' => 255]),
            'target_type' => Schema::string('Target type (project, region, binding, …).', ['maxLength' => 60]),
            'target_id' => Schema::string('Target id.', ['maxLength' => 191]),
            'result' => Schema::enum(['success', 'denied', 'failure'], 'Result.'),
            'from' => Schema::string('From (ISO 8601).', ['maxLength' => 40]),
            'to' => Schema::string('To (ISO 8601).', ['maxLength' => 40]),
            'q' => Schema::string('Free text.', ['maxLength' => 255]),
            'limit' => Schema::integer('Events (default 25).', 1, 100),
        ]);
    }

    public function permissions(): array
    {
        return ['audit.view'];
    }

    public function group(): string
    {
        return 'audit';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/audit-events', [
            'actor' => $arguments['actor'] ?? null,
            'action' => $arguments['action'] ?? null,
            'target_type' => $arguments['target_type'] ?? null,
            'target_id' => $arguments['target_id'] ?? null,
            'result' => $arguments['result'] ?? null,
            'from' => $arguments['from'] ?? null,
            'to' => $arguments['to'] ?? null,
            'q' => $arguments['q'] ?? null,
            'per_page' => (int) ($arguments['limit'] ?? 25),
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Searching the audit log');
        }

        $events = array_values(array_map(fn (array $e) => array_filter([
            'id' => $e['id'] ?? null,
            'occurred_at' => $e['occurred_at'] ?? null,
            'action' => $e['action'] ?? null,
            'result' => $e['result'] ?? null,
            'actor' => array_filter([
                'type' => $e['actor']['type'] ?? null,
                'name' => $e['actor']['name'] ?? null,
                'email' => $e['actor']['email'] ?? null,
                'auth_method' => $e['actor']['auth_method'] ?? null,
            ], fn ($v) => $v !== null),
            'target' => $e['target'] ?? null,
            'status_code' => $e['status_code'] ?? null,
            'metadata' => ! empty($e['metadata']) ? $e['metadata'] : null,
        ], fn ($v) => $v !== null && $v !== []), array_filter((array) $result->get('data', []), 'is_array')));

        return ToolResult::ok([
            'events' => $events,
            'total' => $result->get('meta.total'),
        ], count($events).' event(s) of '.(int) $result->get('meta.total', count($events)).'.');
    }
}
