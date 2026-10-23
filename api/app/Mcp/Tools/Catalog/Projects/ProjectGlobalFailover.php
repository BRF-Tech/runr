<?php

namespace App\Mcp\Tools\Catalog\Projects;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * Failover of a global project whose primary does not answer (docs/global-projects.md,
 * "Failover"): POST /projects/{project}/global/failover.
 */
class ProjectGlobalFailover extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'project_global_failover';
    }

    public function title(): string
    {
        return 'Fail a global project over to another region';
    }

    public function description(): string
    {
        return 'Moves the primary of a global project whose primary region does not answer to another region (region, else the first healthy region in HA order): the target\'s Postgres replica clusters are promoted at once — writes after their last received WAL are lost — the triggers move there, and the old primary is fenced (out of DNS) until it answers again, when its data is rebuilt from the new primary. A primary that still answers moves without loss with project_global_update primary. Ask the user first and pass confirm true; project_global_get follows the move (moving_to).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'region' => Schema::string('The region that becomes the primary (id or name); default: the first healthy region in HA order.', ['maxLength' => 64]),
            'confirm' => Schema::confirm('the failover (writes the replicas did not receive are lost)'),
        ], ['project', 'confirm']);
    }

    public function permissions(): array
    {
        return ['projects.update'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $payload = [];
        if (isset($arguments['region']) && trim((string) $arguments['region']) !== '') {
            $payload['region_id'] = $this->regionId($context, (string) $arguments['region']);
        }

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'global', 'failover'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The project did not fail over');
        }

        $global = (array) $result->data();
        $primary = collect((array) ($global['regions'] ?? []))->firstWhere('role', 'primary');
        $text = ! empty($global['moving_to'])
            ? 'Failing over in the background: the replica clusters are promoted first. project_global_get follows it.'
            : 'Failed over: the primary is '.($primary['region_name'] ?? $global['primary_region_id'] ?? '?').'; the old primary is fenced until it answers again.';

        return ToolResult::ok(['global' => $global], $text);
    }
}
