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
 * Change the mode, failover, primary or regions of a global project (docs/global-projects.md):
 * PUT /projects/{project}/global.
 */
class ProjectGlobalUpdate extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'project_global_update';
    }

    public function title(): string
    {
        return 'Change the regions of a global project';
    }

    public function description(): string
    {
        return 'Changes a global project: its mode (ha or proximity), its failover (automatic: RunR moves the primary to the next healthy region when its health check fails; manual: only an operator moves it), its primary region (only a region whose copy serves the active version — a switchover: dedicated Postgres replicas are promoted without losing a write, the triggers move with it), or its regions (the full list, 2 or more; a new region gets the active version, a region left out has its copy removed). Removing a region stops it from serving the project: ask the user first and pass confirm true. For a primary that does not answer use project_global_failover.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'mode' => Schema::enum(['ha', 'proximity'], 'ha: the primary region, failing over to the others; proximity: the closest healthy region.'),
            'failover' => Schema::enum(['automatic', 'manual'], 'automatic: RunR moves the primary when its health check fails long enough; manual: only an operator moves it.'),
            'primary' => Schema::string('The new primary region (id or name).', ['maxLength' => 64]),
            'regions' => Schema::array(['type' => 'string', 'maxLength' => 64], 'Every region of the project (ids or names), the primary included.', 16),
            'confirm' => Schema::confirm('changing the regions of the project'),
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
        if (isset($arguments['mode'])) {
            $payload['mode'] = (string) $arguments['mode'];
        }
        if (isset($arguments['failover'])) {
            $payload['failover'] = (string) $arguments['failover'];
        }
        if (isset($arguments['primary'])) {
            $payload['primary_region_id'] = $this->regionId($context, (string) $arguments['primary']);
        }
        if (isset($arguments['regions'])) {
            $payload['region_ids'] = array_values(array_map(fn ($r) => $this->regionId($context, (string) $r), (array) $arguments['regions']));
        }
        if ($payload === []) {
            throw new ToolError('Nothing to change: pass mode, failover, primary or regions.');
        }

        $result = $context->api()->put(InternalApi::path('projects', $projectId, 'global'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The project was not changed');
        }

        $global = (array) $result->data();

        return ToolResult::ok(['global' => $global], 'Changed. project_global_get follows the rollout to new regions.');
    }
}
