<?php

namespace App\Mcp\Http\Resources;

use App\Mcp\Hosting\GatewayForwarder;
use App\Mcp\Models\HostedServer;
use App\Mcp\Support\McpUrls;
use App\Models\Project;
use Illuminate\Http\Request;

/** A hosted MCP server as the API shows it (no keys). */
final class HostedServerResource
{
    public static function make(HostedServer $server, ?Project $project, Request $request): array
    {
        return [
            'id' => $server->id,
            'project_id' => $server->project_id,
            'project_name' => $project?->name,
            'handle' => $server->handle,
            'url' => McpUrls::hostedServer($server->handle, $request),
            'access_mode' => $server->access_mode,
            'path' => $server->path,
            'audit_calls' => (bool) $server->audit_calls,
            'function_url' => $project ? app(GatewayForwarder::class)->targetUrl($server, $project) : null,
            'resource_metadata' => $server->requiresToken() ? McpUrls::hostedResourceMetadata($server->handle, $request) : null,
            'key_rotated_at' => $server->key_rotated_at?->toIso8601String(),
            'needs_redeploy' => $project ? self::needsRedeploy($server, $project) : false,
            'created_at' => $server->created_at?->toIso8601String(),
            'updated_at' => $server->updated_at?->toIso8601String(),
        ];
    }

    /** The short form ProjectResource carries. */
    public static function summary(HostedServer $server, ?Request $request = null): array
    {
        return [
            'handle' => $server->handle,
            'url' => McpUrls::hostedServer($server->handle, $request),
            'access_mode' => $server->access_mode,
        ];
    }

    /**
     * The gateway key reaches the workload with a deploy: after a rotation (or before the first
     * deploy of a new server) the running deployment still has the previous key, or none.
     */
    public static function needsRedeploy(HostedServer $server, Project $project): bool
    {
        $active = $project->deployments()->where('status', 'active')->latest()->first();
        if (! $active) {
            return false;
        }

        $since = $server->key_rotated_at ?? $server->created_at;

        return $since !== null && $active->created_at !== null && $active->created_at->lt($since);
    }
}
