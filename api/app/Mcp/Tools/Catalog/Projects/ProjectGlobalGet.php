<?php

namespace App\Mcp\Tools\Catalog\Projects;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * The regions of a global project (docs/global-projects.md): GET /projects/{project}/global.
 */
class ProjectGlobalGet extends Tool
{
    public function name(): string
    {
        return 'project_global_get';
    }

    public function title(): string
    {
        return 'Regions of a global project';
    }

    public function description(): string
    {
        return 'The regions a global project runs in: its global host name, the mode (ha: the primary region, failing over to the others; proximity: the closest healthy region), the failover setting (automatic or manual) and a move of the primary in progress (moving_to), and per region its role, rollout status (fenced: it lost the primary in a failover and rejoins when it answers again), the version it serves, its health, whether DNS answers with it, whether the triggers run there and whether it reaches the project data (data_status). bindings lists each binding and how every region reaches it (direct, data link, replica cluster with its lag). Also what the project should know (warnings). Use it after a deploy to follow the rollout to the other regions.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'global'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the regions of the project');
        }

        $global = (array) $result->data();
        $names = [];
        $lines = [];
        foreach ((array) ($global['regions'] ?? []) as $region) {
            $names[(string) ($region['region_id'] ?? '')] = (string) ($region['region_name'] ?? $region['region_id'] ?? '?');
            $lines[] = sprintf(
                '%s (%s): %s%s%s%s%s',
                $region['region_name'] ?? $region['region_id'] ?? '?',
                $region['role'] ?? '?',
                $region['status'] ?? '?',
                isset($region['version']) ? ', v'.$region['version'] : '',
                ! empty($region['in_dns']) ? ', in DNS' : '',
                ! empty($region['triggers_active']) ? ', triggers' : '',
                ! empty($region['data_status']) ? ', data '.$region['data_status'] : '',
            );
        }

        $summary = ($global['hostname'] ?? 'The project').' — '.($global['mode'] ?? 'ha')
            .(isset($global['failover']) ? ', failover '.$global['failover'] : '');
        if (! empty($global['moving_to'])) {
            $summary .= ', the primary moves to '.($names[(string) $global['moving_to']] ?? $global['moving_to']);
        }

        return ToolResult::ok(['global' => $global], $summary.': '.implode('; ', $lines));
    }
}
