<?php

namespace App\Events;

use App\Models\Notification;
use App\Support\BroadcastChannelResolver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Notification $notification,
    ) {}

    /** A user's notification on their own channel; one for everyone on the shared one. */
    public function broadcastOn(): array
    {
        $userId = $this->notification->user_id;

        return BroadcastChannelResolver::resolve($userId ? "notifications.{$userId}" : 'notifications');
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'user_id' => $this->notification->user_id,
            'type' => $this->notification->type,
            'level' => $this->notification->level,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'data' => $this->notification->data,
            'read_at' => $this->notification->read_at,
            'created_at' => $this->notification->created_at?->toISOString(),
        ];
    }
}
