<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class AlertChannelDelete extends Tool
{
    use ManagesAlerts;

    public function name(): string
    {
        return 'alert_channel_delete';
    }

    public function title(): string
    {
        return 'Delete an alert channel';
    }

    public function description(): string
    {
        return 'Deletes an alert channel; every rule that sent to it stops doing so (a rule left without channels still records alerts). Ask the user before confirming.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'channel' => Schema::string('The channel: id or exact name (alert_channels_list).', ['minLength' => 1, 'maxLength' => 100]),
            'confirm' => Schema::confirm('deleting the alert channel'),
        ], ['channel', 'confirm']);
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = $this->channelId($context, (string) $arguments['channel']);
        $context->setTarget('alert_channel', $id);

        $this->alertCall($context, 'DELETE', self::channelPath($id), [], 'The alert channel was not deleted');

        return ToolResult::ok(['deleted' => true, 'channel_id' => $id], 'Alert channel deleted.');
    }
}
