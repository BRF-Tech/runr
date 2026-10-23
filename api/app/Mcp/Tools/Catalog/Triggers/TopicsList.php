<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class TopicsList extends Tool
{
    public function name(): string
    {
        return 'topics_list';
    }

    public function title(): string
    {
        return 'List topics';
    }

    public function description(): string
    {
        return 'The organization\'s fan-out topics: name, region, stream and how many topic triggers subscribe. With topic, one topic with its subscriptions (project, trigger, start_from). Projects subscribe with trigger_add type topic; topic_publish sends an event to every subscriber.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'topic' => Schema::string('Show this topic (name or id) with its subscriptions.', ['minLength' => 1, 'maxLength' => 100]),
        ]);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'workflows';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (! empty($arguments['topic'])) {
            $result = $context->api()->get(InternalApi::path('topics', (string) $arguments['topic']));
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the topic');
            }
            $topic = self::compact((array) $result->data());
            $context->setTarget('topic', $topic['id'] ?? null, $topic['name'] ?? null);

            return ToolResult::ok(['topic' => $topic], 'Topic '.($topic['name'] ?? '').': '.count((array) ($topic['subscriptions'] ?? [])).' subscription(s).');
        }

        $result = $context->api()->get('/topics');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Listing the topics');
        }
        $topics = array_map(fn (array $t) => self::compact($t), array_values(array_filter((array) $result->data(), 'is_array')));

        return ToolResult::ok(['topics' => $topics], count($topics).' topic(s).');
    }

    private static function compact(array $topic): array
    {
        return array_filter($topic, fn ($v) => $v !== null && $v !== '');
    }
}
