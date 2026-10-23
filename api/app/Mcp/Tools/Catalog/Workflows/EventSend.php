<?php

namespace App\Mcp\Tools\Catalog\Workflows;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class EventSend extends Tool
{
    public function name(): string
    {
        return 'event_send';
    }

    public function title(): string
    {
        return 'Send a workflow event';
    }

    public function description(): string
    {
        return 'Sends an event to the organization\'s durable workflows: it starts every workflow triggered by the event name and resumes runs waiting for it (step.waitForEvent) — in one environment: environment (a name such as staging) reaches the workflows of the environments of that name, default production. Pass an id to make a repeated send a no-op.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('Event name, e.g. "order.created" (names starting with "runr/" are reserved).', ['minLength' => 1, 'maxLength' => 200]),
            'data' => ['type' => 'object', 'description' => 'Event payload (JSON object).'],
            'id' => Schema::string('Event id for de-duplication.', ['maxLength' => 200]),
            'environment' => Schema::string('Environment name the event belongs to (default: production).', ['maxLength' => 20]),
        ], ['name']);
    }

    public function permissions(): array
    {
        return ['events.send'];
    }

    public function group(): string
    {
        return 'workflows';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $event = [
            'name' => (string) $arguments['name'],
            'data' => (object) ((array) ($arguments['data'] ?? [])),
        ];
        if (! empty($arguments['id'])) {
            $event['id'] = (string) $arguments['id'];
        }
        if (isset($arguments['environment']) && $arguments['environment'] !== '') {
            $event['environment'] = (string) $arguments['environment'];
        }

        $result = $context->api()->post('/events', $event);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The event was not accepted');
        }

        $events = (array) $result->get('events', []);
        $context->setTarget('event', (string) ($result->get('ids.0') ?? ''), (string) $arguments['name']);

        return ToolResult::ok(['events' => $events], 'Event '.$arguments['name'].' accepted.');
    }
}
