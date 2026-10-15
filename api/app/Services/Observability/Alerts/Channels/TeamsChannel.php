<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Models\Observability\AlertChannel;
use App\Services\Observability\Alerts\AlertMessage;

/**
 * Microsoft Teams through a Workflows webhook ("When a Teams webhook request is received" →
 * post the card): the message is an Adaptive Card attachment.
 */
class TeamsChannel extends HttpChannel
{
    public function rules(): array
    {
        return ['config.webhook_url' => ['required', 'string', 'url:https', 'max:1000']];
    }

    public function send(AlertChannel $channel, AlertMessage $message): DeliveryResult
    {
        return $this->post((string) ($channel->config['webhook_url'] ?? ''), self::payload($message));
    }

    public static function payload(AlertMessage $m): array
    {
        $facts = [];
        foreach ($m->fields as $label => $value) {
            $facts[] = ['title' => (string) $label, 'value' => (string) $value];
        }

        $color = match (true) {
            $m->status === 'resolved' => 'Good',
            $m->severity === 'critical' => 'Attention',
            $m->severity === 'warning' => 'Warning',
            default => 'Accent',
        };

        $body = [
            ['type' => 'TextBlock', 'text' => $m->title, 'weight' => 'Bolder', 'size' => 'Medium', 'color' => $color, 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $m->text !== '' ? $m->text : $m->title, 'wrap' => true],
        ];
        if ($facts) {
            $body[] = ['type' => 'FactSet', 'facts' => $facts];
        }

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => $body,
        ];
        if ($m->url) {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open in RunR', 'url' => $m->url]];
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => $card,
            ]],
        ];
    }
}
