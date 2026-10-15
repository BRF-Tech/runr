<?php

namespace App\Mcp\Tools\Catalog\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class McpServerConfigure extends Tool
{
    public function name(): string
    {
        return 'mcp_server_configure';
    }

    public function title(): string
    {
        return 'Configure a hosted MCP server';
    }

    public function description(): string
    {
        return 'Turns a project into a hosted MCP server behind the RunR gateway, or changes one: access mode (public: anyone; token: callers with a RunR API token that holds mcp.servers.call on the project; oauth: like token, and MCP clients can connect with one click through RunR OAuth), the MCP endpoint path on the server, and whether every call is also written to the audit log. rotate_key issues a new gateway signing key (redeploy so the server receives it).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'access_mode' => Schema::enum(['public', 'token', 'oauth'], 'Who may call the server.'),
            'path' => Schema::string('Path of the MCP endpoint on the server (default "/mcp").', ['maxLength' => 200, 'pattern' => '^/[A-Za-z0-9/_.-]*$']),
            'audit_calls' => Schema::boolean('Also record every call in the audit log (calls always go to the project\'s call log).'),
            'rotate_key' => Schema::boolean('Issue a new gateway signing key.'),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['mcp.servers.manage'];
    }

    public function group(): string
    {
        return 'mcp';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $changes = array_filter([
            'access_mode' => $arguments['access_mode'] ?? null,
            'path' => $arguments['path'] ?? null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('audit_calls', $arguments)) {
            $changes['audit_calls'] = (bool) $arguments['audit_calls'];
        }

        if ($changes === [] && empty($arguments['rotate_key'])) {
            throw new ToolError('Nothing to change: pass access_mode, path, audit_calls or rotate_key.');
        }

        $server = null;
        if ($changes !== []) {
            $result = $context->api()->put(InternalApi::path('projects', $projectId, 'mcp-server'), $changes);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'The MCP server was not changed');
            }
            $server = (array) $result->data();
        }

        if (! empty($arguments['rotate_key'])) {
            $result = $context->api()->post(InternalApi::path('projects', $projectId, 'mcp-server', 'rotate-key'));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'The key was not rotated');
            }
            $server = (array) $result->data();
        }

        $summary = McpServersList::summary((array) $server);

        return ToolResult::ok(['mcp_server' => $summary], sprintf(
            'MCP server at %s, access %s.%s',
            $summary['url'] ?? '?',
            $summary['access_mode'] ?? '?',
            ! empty($summary['needs_redeploy']) ? ' Redeploy (deploy) so the server receives the new key.' : '',
        ));
    }
}
