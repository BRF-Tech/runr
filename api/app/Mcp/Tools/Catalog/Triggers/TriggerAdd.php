<?php

namespace App\Mcp\Tools\Catalog\Triggers;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsTriggers;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class TriggerAdd extends Tool
{
    use ReadsTriggers;

    public function name(): string
    {
        return 'trigger_add';
    }

    public function title(): string
    {
        return 'Add a trigger';
    }

    public function description(): string
    {
        return 'Adds a trigger to a project: a schedule, a queue, a webhook inbox (a stable public URL that verifies the sender), a topic subscription, platform events, … A project has at most one http trigger; a workflow trigger must be alone. Secrets (a webhook\'s signing_secret) are write-only and never returned. The change reaches the cluster with the next deploy (deploy tool). '.self::configHelp();
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'type' => Schema::enum(self::triggerTypes(), 'Trigger type.'),
            'name' => Schema::string('Trigger name: lower-case letters, digits and dashes (default: the type).', ['maxLength' => 63, 'pattern' => '^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$']),
            'config' => self::configArgument('Settings of the trigger (see the description), e.g. {"verify": "github"} or {"cron": "0 3 * * *", "timezone": "Europe/Berlin"}.'),
            'secrets' => self::secretsArgument('Write-only secrets, e.g. {"signing_secret": "…"} for a webhook.'),
            'enabled' => Schema::boolean('Whether the trigger is on (default true).', true),
            'primary' => Schema::boolean('Make it the primary trigger (first in the list).', false),
        ], ['project', 'type']);
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
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $payload = ['type' => (string) $arguments['type']];
        if (! empty($arguments['name'])) {
            $payload['name'] = (string) $arguments['name'];
        }
        if (array_key_exists('enabled', $arguments)) {
            $payload['enabled'] = (bool) $arguments['enabled'];
        }
        if (! empty($arguments['primary'])) {
            $payload['position'] = 0;
        }
        if (isset($arguments['config'])) {
            $payload['config'] = (object) (array) $arguments['config'];
        }
        if (! empty($arguments['secrets'])) {
            $payload['secrets'] = array_map('strval', (array) $arguments['secrets']);
        }

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'triggers'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The trigger was not added');
        }

        $trigger = self::triggerSummary((array) $result->data());

        $summary = 'Added trigger '.($trigger['name'] ?? $payload['type']).' ('.$payload['type'].').';
        if (! empty($trigger['url'])) {
            $summary .= ' Its inbox URL: '.$trigger['url'].'.';
        }

        return ToolResult::ok([
            'trigger' => $trigger,
            'requires_deploy' => (bool) $result->get('requires_deploy', true),
        ], $summary.' It takes effect with the next deploy.');
    }
}
