<?php

namespace Modules\Ticket\Events;

use App\Support\BroadcastChannelResolver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Ticket\Models\SupportTicket;
use Modules\Ticket\Models\SupportTicketReply;

class SupportTicketReplied implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketReply $reply,
    ) {}

    public function broadcastAs(): string
    {
        return 'SupportTicketReplied';
    }

    public function broadcastOn(): array
    {
        // Private; also unprefixed so platform admins (no tenant context) receive it
        return BroadcastChannelResolver::resolveWithPlatform("support-ticket.{$this->ticket->id}");
    }

    public function broadcastWith(): array
    {
        $user = $this->reply->user;

        return [
            'reply' => [
                'id' => $this->reply->id,
                'ticket_id' => $this->ticket->id,
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
                'message' => $this->reply->message,
                'is_staff' => $this->reply->is_staff,
                'attachments' => $this->reply->attachments,
                'created_at' => $this->reply->created_at?->toISOString(),
            ],
        ];
    }
}
