<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class AlertChannelCreate extends Tool
{
    use ManagesAlerts;

    public function name(): string
    {
        return 'alert_channel_create';
    }

    public function title(): string
    {
        return 'Create an alert channel';
    }

    public function description(): string
    {
        return 'Adds a channel alerts are sent to: email (config.emails), slack or teams (config.webhook_url, https), telegram (config.bot_token, config.chat_id) or webhook (config.url, optional config.secret for an HMAC signature). Secrets are stored write-only: the answer shows them masked. Private addresses are refused for webhook URLs. Then attach it to rules (alert_rule_create / alert_rule_update channels) and check it with alert_channel_test.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('Name of the channel.', ['minLength' => 1, 'maxLength' => 100]),
            'type' => Schema::enum(self::$channelTypes, 'Kind of channel.'),
            'config' => self::channelConfigSchema(),
            'is_enabled' => Schema::boolean('Send alerts to it (default true).', true),
        ], ['name', 'type', 'config']);
    }

    public function permissions(): array
    {
        return ['alerts.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $channel = (array) $this->alertCall($context, 'POST', '/observability/alert-channels', array_filter([
            'name' => (string) $arguments['name'],
            'type' => (string) $arguments['type'],
            'config' => (object) $arguments['config'],
            'is_enabled' => $arguments['is_enabled'] ?? null,
        ], fn ($v) => $v !== null), 'The alert channel was not created')->data();

        $context->setTarget('alert_channel', (string) ($channel['id'] ?? ''), (string) ($channel['name'] ?? ''));

        return ToolResult::ok(['channel' => $channel], 'Alert channel '.($channel['name'] ?? '').' ('.($channel['type'] ?? '').') created.');
    }
}
