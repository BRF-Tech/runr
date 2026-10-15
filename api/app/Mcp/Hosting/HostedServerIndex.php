<?php

namespace App\Mcp\Hosting;

use App\Mcp\Http\Resources\HostedServerResource;
use App\Mcp\Models\HostedServer;
use App\Models\Project;
use App\Support\TenantAccess;
use Illuminate\Http\Request;

/**
 * The hosted MCP servers of the current organization by project id, read once per request
 * (scoped binding): ProjectResource names a project's server (the "MCP server" badge) without a
 * query per project of a list.
 */
final class HostedServerIndex
{
    /** @var array<string, HostedServer>|null */
    private ?array $servers = null;

    public function forProject(string $projectId): ?HostedServer
    {
        if ($this->servers === null) {
            try {
                $this->servers = HostedServer::query()
                    ->where('tenant_id', TenantAccess::currentId())
                    ->get()
                    ->keyBy('project_id')
                    ->all();
            } catch (\Throwable $e) {
                report($e); // the table is created by the migration of this release

                $this->servers = [];
            }
        }

        return $this->servers[$projectId] ?? null;
    }

    public function forget(): void
    {
        $this->servers = null;
    }

    /** ProjectResource's mcp_server field: {handle, url, access_mode} or null. */
    public static function summaryFor(Project $project, ?Request $request = null): ?array
    {
        $server = app(self::class)->forProject((string) $project->getKey());

        return $server ? HostedServerResource::summary($server, $request) : null;
    }
}
