<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class TopicPublish extends Tool
{
    public function name(): string
    {
        return 'topic_publish';
    }

    public function title(): string
    {
        return 'Publish an event to a topic';
    }

    public function description(): string
    {
        return 'Publishes one event to a topic of the organization: every project with a topic trigger on it receives the event as a CloudEvent. data is any JSON value; with a content_type other than application/json a string data is sent as is. type, subject and id become the CloudEvents type, subject and id.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'topic' => Schema::string('The topic: its name or id (topics_list).', ['minLength' => 1, 'maxLength' => 100]),
            'data' => ['description' => 'The event data: any JSON value (an object, a list, a string, …).'],
            'content_type' => Schema::string('Content type of the data (default application/json).', ['maxLength' => 255]),
            'type' => Schema::string('CloudEvents type, e.g. "order.created".', ['maxLength' => 255]),
            'subject' => Schema::string('CloudEvents subject.', ['maxLength' => 255]),
            'id' => Schema::string('Event id (generated when left out).', ['maxLength' => 255]),
        ], ['topic', 'data']);
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
        $topic = (string) $arguments['topic'];
        // An empty JSON object and an empty list decode alike; an event's data is mostly an object
        $data = $arguments['data'] ?? null;
        $payload = ['data' => $data === [] ? new \stdClass : $data];
        foreach (['content_type', 'type', 'subject', 'id'] as $key) {
            if (isset($arguments[$key]) && $arguments[$key] !== '') {
                $payload[$key] = (string) $arguments[$key];
            }
        }

        $result = $context->api()->post(InternalApi::path('topics', $topic, 'publish'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The event was not published');
        }

        $published = (array) $result->data();
        $context->setTarget('topic', null, $topic);

        return ToolResult::ok(['published' => $published], 'Published event '.($published['id'] ?? '')." to topic {$topic}.");
    }
}
