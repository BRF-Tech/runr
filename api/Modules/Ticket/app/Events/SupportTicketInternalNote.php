<?php

namespace Modules\Ticket\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Ticket\Models\SupportTicket;
use Modules\Ticket\Models\SupportTicketReply;

class SupportTicketInternalNote implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketReply $reply,
    ) {}

    public function broadcastAs(): string
    {
        return 'SupportTicketInternalNote';
    }

    /**
     * Internal notes are admin-only: a private channel of their own that only ticket managers
     * may join (routes/channels.php) — never the ticket channel its author follows.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("support-ticket.{$this->ticket->id}.internal")];
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
                'is_internal' => true,
                'attachments' => $this->reply->attachments,
                'created_at' => $this->reply->created_at?->toISOString(),
            ],
        ];
    }
}
