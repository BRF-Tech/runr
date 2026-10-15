<?php

namespace App\Events;

use App\Support\BroadcastChannelResolver;
use App\Support\Secrets\SecretRedactor;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic platform-wide broadcast event (sync — bypasses queue).
 *
 * ShouldRescue: a broadcast that fails (WebSocket server unreachable) is reported, not thrown —
 * the change that raised it is already saved and its request must not answer 500.
 *
 * Topics: region.updated, project.updated, project.deleted,
 *         deployment.status, settings.updated, user.updated,
 *         install.progress, *.installed, *_failed
 */
class PlatformEvent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $topic,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return BroadcastChannelResolver::resolve('platform');
    }

    public function broadcastAs(): string
    {
        return 'platform.event';
    }

    /**
     * The platform channel is public: env var values and secret-looking fields are masked
     * (SecretRedactor) — clients that need them load the resource through the API.
     */
    public function broadcastWith(): array
    {
        return [
            'topic' => $this->topic,
            'payload' => SecretRedactor::redact($this->payload),
        ];
    }
}
