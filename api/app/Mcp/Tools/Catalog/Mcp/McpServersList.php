<?php

namespace App\Mcp\Tools\Catalog\Mcp;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class McpServersList extends Tool
{
    public function name(): string
    {
        return 'mcp_servers_list';
    }

    public function title(): string
    {
        return 'List hosted MCP servers';
    }

    public function description(): string
    {
        return 'Lists the MCP servers hosted as RunR projects that you can see: the gateway URL MCP clients connect to, the access mode (public, RunR token, OAuth through RunR), and whether a redeploy is due after a key rotation.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return ['projects.view'];
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
        $result = $context->api()->get('/mcp-servers');
        if (! $result->ok()) {
            throw ToolError::fromApi($result);
        }

        $servers = array_values(array_map([self::class, 'summary'], array_filter((array) $result->get('data', []), 'is_array')));

        return ToolResult::ok(['servers' => $servers], count($servers).' hosted MCP server(s).');
    }

    /** The fields of a hosted server an agent needs. */
    public static function summary(array $server): array
    {
        return array_filter([
            'id' => $server['id'] ?? null,
            'project_id' => $server['project_id'] ?? null,
            'handle' => $server['handle'] ?? null,
            'url' => $server['url'] ?? null,
            'access_mode' => $server['access_mode'] ?? null,
            'path' => $server['path'] ?? null,
            'audit_calls' => $server['audit_calls'] ?? null,
            'key_rotated_at' => $server['key_rotated_at'] ?? null,
            'needs_redeploy' => $server['needs_redeploy'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
