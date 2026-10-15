<?php

namespace App\Services\Observability\Alerts\Channels;

use InvalidArgumentException;

/** Channel type → sender. */
class ChannelRegistry
{
    private const SENDERS = [
        'email' => EmailChannel::class,
        'slack' => SlackChannel::class,
        'teams' => TeamsChannel::class,
        'telegram' => TelegramChannel::class,
        'webhook' => WebhookChannel::class,
    ];

    public function for(string $type): ChannelSender
    {
        $class = self::SENDERS[$type] ?? throw new InvalidArgumentException("Unknown alert channel type {$type}");

        return app($class);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys(self::SENDERS);
    }
}
