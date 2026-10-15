<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsUptime;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class UptimeCheckDelete extends Tool
{
    use ReadsUptime;

    public function name(): string
    {
        return 'uptime_check_delete';
    }

    public function title(): string
    {
        return 'Delete an uptime check';
    }

    public function description(): string
    {
        return 'Deletes an uptime check of a project with its results and incidents (it also leaves the status page). Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'check' => Schema::string('The check: its id or exact name.', ['minLength' => 1, 'maxLength' => 100]),
            'confirm' => Schema::confirm('deleting the check with its results and incidents'),
        ], ['project', 'check', 'confirm']);
    }

    public function permissions(): array
    {
        return ['uptime.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $check = $this->findUptimeCheck($context, $projectId, (string) $arguments['check']);

        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'uptime-checks', (string) $check['id']));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The uptime check was not deleted');
        }

        return ToolResult::ok(['deleted' => ['id' => $check['id'], 'name' => $check['name'] ?? null]], 'Uptime check "'.($check['name'] ?? $check['id']).'" deleted.');
    }
}
