<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Models\Observability\AlertChannel;
use App\Services\Observability\Alerts\AlertMessage;

/** Slack incoming webhook (any Slack-compatible incoming webhook). */
class SlackChannel extends HttpChannel
{
    private const COLORS = ['critical' => '#d1242f', 'warning' => '#d4a72c', 'info' => '#0969da', 'resolved' => '#1a7f37'];

    public function rules(): array
    {
        return ['config.webhook_url' => ['required', 'string', 'url:https', 'max:500']];
    }

    public function send(AlertChannel $channel, AlertMessage $message): DeliveryResult
    {
        return $this->post((string) ($channel->config['webhook_url'] ?? ''), self::payload($message));
    }

    public static function payload(AlertMessage $m): array
    {
        $fields = [];
        foreach ($m->fields as $label => $value) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*{$label}*\n{$value}"];
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_substr($m->title, 0, 150)]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr($m->text !== '' ? $m->text : $m->title, 0, 2900)]],
        ];
        if ($fields) {
            $blocks[] = ['type' => 'section', 'fields' => array_slice($fields, 0, 10)];
        }
        if ($m->url) {
            $blocks[] = ['type' => 'actions', 'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Open in RunR'],
                'url' => $m->url,
            ]]];
        }

        return [
            'text' => $m->title,
            'blocks' => $blocks,
            'attachments' => [['color' => self::COLORS[$m->status === 'resolved' ? 'resolved' : $m->severity] ?? self::COLORS['info'], 'fallback' => $m->title]],
        ];
    }
}
