<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class AlertChannelSendTest extends Tool
{
    use ManagesAlerts;

    public function name(): string
    {
        return 'alert_channel_test';
    }

    public function title(): string
    {
        return 'Send a test alert';
    }

    public function description(): string
    {
        return 'Sends a test message through an alert channel now and returns whether it was delivered (response code, error) — to check a new or changed channel. The recipients really get the message.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'channel' => Schema::string('The channel: id or exact name (alert_channels_list).', ['minLength' => 1, 'maxLength' => 100]),
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
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = $this->channelId($context, (string) $arguments['channel']);
        $context->setTarget('alert_channel', $id);

        $data = (array) $this->alertCall($context, 'POST', self::channelPath($id, 'test'), [], 'The test message was not sent')->data();
        $ok = (bool) ($data['ok'] ?? false);

        return $ok
            ? ToolResult::ok($data, 'The test message was delivered.')
            : ToolResult::error('The test message was not delivered'.(! empty($data['error']) ? ': '.$data['error'] : '').'.', ['result' => $data]);
    }
}
