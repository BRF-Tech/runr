<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsTriggers;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class TriggerRemove extends Tool
{
    use ReadsTriggers;

    public function name(): string
    {
        return 'trigger_remove';
    }

    public function title(): string
    {
        return 'Remove a trigger';
    }

    public function description(): string
    {
        return 'Removes a trigger from a project, with the events a webhook or platform_event trigger stored; a webhook\'s inbox URL stops working. The last trigger cannot be removed (disable it with trigger_update instead). The change reaches the cluster with the next deploy. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'trigger' => self::triggerArgument(),
            'confirm' => Schema::confirm('removing the trigger and its stored events'),
        ], ['project', 'trigger', 'confirm']);
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
        $reference = (string) $arguments['trigger'];

        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'triggers', $reference));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The trigger was not removed');
        }

        // what the removal left outside RunR that RunR drops later (a cdc trigger's slots)
        $cleanup = array_values(array_filter((array) $result->get('cleanup', []), 'is_array'));
        $notes = implode(' ', array_map(fn (array $c) => (string) ($c['message'] ?? ''), $cleanup));

        return ToolResult::ok(array_filter([
            'removed' => $reference,
            'requires_deploy' => (bool) $result->get('requires_deploy', true),
            'cleanup' => $cleanup ?: null,
        ], fn ($v) => $v !== null), trim("Removed trigger {$reference}. The change takes effect with the next deploy. {$notes}"));
    }
}
