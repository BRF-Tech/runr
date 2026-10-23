<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Tools\Concerns\ReadsTriggers;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class TriggersList extends Tool
{
    use ReadsTriggers;

    public function name(): string
    {
        return 'triggers_list';
    }

    public function title(): string
    {
        return 'List a project\'s triggers';
    }

    public function description(): string
    {
        return 'The triggers of a project in order — what invokes its function: http (public URL), schedule, queue, workflow, webhook (with its public inbox URL), topic and platform_event — with their config, whether each is enabled and primary, where it runs (service, gateway, cronjob), a schedule\'s time zone (effective_timezone; timezone_source organization when it follows the organization\'s zone) and which secrets are set (names only). The plan says what the list deploys.';
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
        $list = $this->triggerList($context, $projectId);
        $triggers = array_map(fn (array $t) => self::triggerSummary($t), $list['triggers']);

        $names = array_map(fn (array $t) => ($t['name'] ?? '?').' ('.($t['type'] ?? '?').(! empty($t['primary']) ? ', primary' : '').(($t['enabled'] ?? true) ? '' : ', disabled').')', $triggers);

        return ToolResult::ok([
            'triggers' => $triggers,
            'plan' => (object) $list['plan'],
        ], count($triggers).' trigger(s): '.implode(', ', $names).'.');
    }
}
