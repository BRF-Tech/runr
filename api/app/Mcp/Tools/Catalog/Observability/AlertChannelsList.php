<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class AlertChannelsList extends Tool
{
    use ManagesAlerts;

    public function name(): string
    {
        return 'alert_channels_list';
    }

    public function title(): string
    {
        return 'List alert channels';
    }

    public function description(): string
    {
        return 'The organization\'s alert channels (email, Slack, Teams, Telegram, webhook) with their id, type, masked configuration, enabled flag and the result of the last test. Alert rules send to channels by id.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
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
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $channels = array_values(array_filter((array) $this->alertCall($context, 'GET', '/observability/alert-channels', [], 'Reading the alert channels')->data(), 'is_array'));

        return ToolResult::ok(['channels' => $channels, 'count' => count($channels)], count($channels).' alert channel(s).');
    }
}
