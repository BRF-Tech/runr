<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsTriggers;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WebhookEventReplay extends Tool
{
    use ReadsTriggers;

    public function name(): string
    {
        return 'webhook_event_replay';
    }

    public function title(): string
    {
        return 'Replay stored webhook events';
    }

    public function description(): string
    {
        return 'Delivers stored events of a webhook or platform_event trigger to the function again: one event (event) or every event in a status (status failed — the default — delivered or all; at most 500 per call). The function really processes them again, so ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'trigger' => self::triggerArgument(),
            'event' => Schema::string('The event id (webhook_events_list); without it, every event of status is replayed.', ['minLength' => 1, 'maxLength' => 64]),
            'status' => Schema::enum(['failed', 'delivered', 'all'], 'Without event: which events to replay (ignored with event).', 'failed'),
            'confirm' => Schema::confirm('delivering the events to the function again'),
        ], ['project', 'trigger', 'confirm']);
    }

    public function permissions(): array
    {
        return ['replay.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $trigger = (string) $arguments['trigger'];

        if (! empty($arguments['event'])) {
            $result = $context->api()->post(InternalApi::path('projects', $projectId, 'triggers', $trigger, 'events', (string) $arguments['event'], 'replay'));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'The event was not replayed');
            }
            $event = array_filter((array) $result->data(), fn ($v) => $v !== null && $v !== '');

            return ToolResult::ok(['event' => $event, 'replayed' => 1], 'Event '.($event['id'] ?? $arguments['event']).' is queued for delivery again.');
        }

        $status = (string) ($arguments['status'] ?? 'failed');
        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'triggers', $trigger, 'events', 'replay'), ['status' => $status]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The events were not replayed');
        }
        $count = (int) $result->get('replayed', 0);

        return ToolResult::ok(['replayed' => $count, 'status' => $status], "{$count} event(s) of trigger {$trigger} queued for delivery again.");
    }
}
