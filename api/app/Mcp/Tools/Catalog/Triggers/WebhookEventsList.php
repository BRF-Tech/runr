<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsTriggers;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WebhookEventsList extends Tool
{
    use ReadsTriggers;

    /** Longest body text a result carries. */
    private const MAX_BODY = 16384;

    public function name(): string
    {
        return 'webhook_events_list';
    }

    public function title(): string
    {
        return 'List stored webhook events';
    }

    public function description(): string
    {
        return 'The events a webhook or platform_event trigger stored, newest first: CloudEvents type, delivery status (pending, delivering, delivered, failed), attempts, replays, size and the last delivery error. With event, one event with the sender\'s headers and the body. webhook_event_replay delivers events again.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'trigger' => self::triggerArgument(),
            'status' => Schema::enum(['pending', 'delivering', 'delivered', 'failed'], 'Only events in this status.'),
            'limit' => Schema::integer('How many events (default 25).', 1, 100),
            'event' => Schema::string('Show this event (its id) with headers and body instead of the list.', ['minLength' => 1, 'maxLength' => 64]),
        ], ['project', 'trigger']);
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
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $trigger = (string) $arguments['trigger'];
        $base = InternalApi::path('projects', $projectId, 'triggers', $trigger, 'events');

        if (! empty($arguments['event'])) {
            $result = $context->api()->get($base.'/'.rawurlencode((string) $arguments['event']));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the event');
            }

            $event = (array) $result->data();
            $text = $event['body_text'] ?? null;
            if (is_string($text) && strlen($text) > self::MAX_BODY) {
                $event['body_text'] = mb_strcut($text, 0, self::MAX_BODY);
                $event['shortened'] = true;
            }
            if (is_string($text)) {
                unset($event['body_base64']);
            } elseif (strlen((string) ($event['body_base64'] ?? '')) > self::MAX_BODY) {
                unset($event['body_base64']);
                $event['body_omitted'] = 'The body is not UTF-8 text and too large for a tool result.';
            }

            return ToolResult::ok(['event' => self::compact($event)], 'Event '.($event['id'] ?? '').': '.($event['status'] ?? '?').'.');
        }

        $result = $context->api()->get($base, [
            'status' => $arguments['status'] ?? null,
            'per_page' => $arguments['limit'] ?? null,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the events (only webhook and platform_event triggers store events)');
        }

        $events = array_map(fn (array $e) => self::compact($e), array_values(array_filter((array) $result->data(), 'is_array')));

        return ToolResult::ok([
            'events' => $events,
            'total' => $result->get('meta.total'),
        ], ($result->get('meta.total') ?? count($events))." event(s) of trigger {$trigger}.");
    }

    private static function compact(array $event): array
    {
        return array_filter($event, fn ($v) => $v !== null && $v !== '');
    }
}
