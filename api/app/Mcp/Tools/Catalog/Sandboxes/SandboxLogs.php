<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxLogs extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_logs';
    }

    public function title(): string
    {
        return 'Activity log of a sandbox';
    }

    public function description(): string
    {
        return 'The activity log of a sandbox, newest first: create, start, commands with exit codes, file transfers, processes, pause / resume, network changes, the end and its reason. With pod: true also the pod\'s phase, conditions, Kubernetes events and container log — what to read when a sandbox will not start.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'pod' => Schema::boolean('Add the pod\'s state, events and log (default false).', false),
            'limit' => Schema::integer('At most this many events (default 100).', 1, 500),
        ], ['sandbox']);
    }

    public function permissions(): array
    {
        return ['sandboxes.view'];
    }

    public function group(): string
    {
        return 'sandboxes';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $context->setTarget('sandbox', $id);

        $data = (array) $this->sandboxCall($context, 'GET', self::sandboxPath($id, 'logs'), [], ['pod' => ! empty($arguments['pod']) ? 1 : null], 'Reading the activity log')->data();
        $events = array_slice(array_values(array_filter((array) ($data['events'] ?? []), 'is_array')), 0, (int) ($arguments['limit'] ?? 100));
        $events = array_map(fn (array $e) => array_filter([
            'at' => $e['created_at'] ?? null,
            'type' => $e['type'] ?? null,
            'message' => $e['message'] ?? null,
            'data' => $e['data'] ?? null,
        ], fn ($v) => $v !== null && $v !== []), $events);

        $result = ['events' => $events];
        if (isset($data['pod']) && is_array($data['pod'])) {
            $result['pod'] = self::capOutput($data['pod'], ['log']);
        }

        return ToolResult::ok($result, count($events).' event(s).');
    }
}
