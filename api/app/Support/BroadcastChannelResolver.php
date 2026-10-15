<?php

namespace App\Support;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Pluggable channel name resolver for broadcast events.
 *
 * Every channel is private: a subscription is authorized by POST /api/broadcasting/auth against
 * routes/channels.php (App\Support\Broadcasting\ChannelAccess). The base app broadcasts to simple
 * names (e.g. "platform" → private-platform); modules can register a resolver to prefix or
 * transform them (e.g. "tenant.{id}.platform") without the base app being aware.
 */
class BroadcastChannelResolver
{
    private static ?\Closure $resolver = null;

    public static function setResolver(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Resolve a logical channel name into broadcast Channel(s).
     *
     * @return array<int, Channel>
     */
    public static function resolve(string $channelName): array
    {
        if (self::$resolver) {
            return (self::$resolver)($channelName);
        }

        return [new PrivateChannel($channelName)];
    }

    /**
     * The resolved channels plus the unprefixed one, for events platform admins follow outside
     * any tenant context as well (support tickets).
     *
     * @return array<int, Channel>
     */
    public static function resolveWithPlatform(string $channelName): array
    {
        $channels = self::resolve($channelName);
        $plain = new PrivateChannel($channelName);

        if (! in_array($plain->name, array_map(fn (Channel $c) => $c->name, $channels), true)) {
            $channels[] = $plain;
        }

        return $channels;
    }
}
