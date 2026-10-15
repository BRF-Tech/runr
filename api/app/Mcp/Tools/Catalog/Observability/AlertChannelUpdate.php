<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class AlertChannelUpdate extends Tool
{
    use ManagesAlerts;

    public function name(): string
    {
        return 'alert_channel_update';
    }

    public function title(): string
    {
        return 'Change an alert channel';
    }

    public function description(): string
    {
        return 'Renames, enables / disables or reconfigures an alert channel. Keys left out of config keep their stored value — a secret is changed only by sending it again. The type cannot change.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'channel' => Schema::string('The channel: id or exact name (alert_channels_list).', ['minLength' => 1, 'maxLength' => 100]),
            'name' => Schema::string('New name.', ['minLength' => 1, 'maxLength' => 100]),
            'config' => self::channelConfigSchema(),
            'is_enabled' => Schema::boolean('Send alerts to it.'),
        ], ['channel']);
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
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $body = array_filter([
            'name' => $arguments['name'] ?? null,
            'config' => isset($arguments['config']) ? (object) $arguments['config'] : null,
            'is_enabled' => $arguments['is_enabled'] ?? null,
        ], fn ($v) => $v !== null);
        if ($body === []) {
            throw new ToolError('Nothing to change: give name, config or is_enabled.');
        }

        $id = $this->channelId($context, (string) $arguments['channel']);
        $channel = (array) $this->alertCall($context, 'PUT', self::channelPath($id), $body, 'The alert channel was not changed')->data();
        $context->setTarget('alert_channel', $id, (string) ($channel['name'] ?? ''));

        return ToolResult::ok(['channel' => $channel], 'Alert channel '.($channel['name'] ?? $id).' changed.');
    }
}
