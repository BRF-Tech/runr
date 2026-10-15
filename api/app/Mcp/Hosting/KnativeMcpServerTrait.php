<?php

namespace App\Mcp\Hosting;

use App\Mcp\Models\HostedServer;
use App\Models\Project;

/**
 * Deploy side of hosted MCP servers (used by KnativeDeployService next to KnativeSecretEnvTrait):
 * a project served as an MCP server gets its gateway key as RUNR_MCP_GATEWAY_KEY — the server
 * checks X-RunR-Signature with it (docs/mcp.md, "Gateway headers"). The key travels in the
 * project's secret env Secret (<slug>-secret-env, secretKeyRef), never as a value in the spec.
 *
 * Requires the consuming class to provide secretEnvName().
 */
trait KnativeMcpServerTrait
{
    private function hostedMcpServer(Project $project): ?HostedServer
    {
        try {
            return HostedServer::forProject($project);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function withMcpGatewayEnv(array $container, Project $project): array
    {
        if (! $this->hostedMcpServer($project)) {
            return $container;
        }

        $env = array_values(array_filter(
            $container['env'] ?? [],
            fn ($e) => ($e['name'] ?? null) !== 'RUNR_MCP_GATEWAY_KEY',
        ));
        $env[] = ['name' => 'RUNR_MCP_GATEWAY_KEY', 'valueFrom' => ['secretKeyRef' => [
            'name' => $this->secretEnvName($project),
            'key' => 'RUNR_MCP_GATEWAY_KEY',
        ]]];
        $container['env'] = $env;

        return $container;
    }

    /** @return array<string, string> entries of the project's secret env Secret */
    private function mcpGatewaySecretEnv(Project $project): array
    {
        $server = $this->hostedMcpServer($project);

        return $server ? ['RUNR_MCP_GATEWAY_KEY' => (string) $server->signing_key] : [];
    }
}
