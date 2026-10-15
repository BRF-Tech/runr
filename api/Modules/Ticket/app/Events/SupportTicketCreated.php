<?php

namespace Modules\Ticket\Events;

use App\Support\BroadcastChannelResolver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Ticket\Models\SupportTicket;

class SupportTicketCreated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly SupportTicket $ticket) {}

    public function broadcastAs(): string
    {
        return 'SupportTicketCreated';
    }

    public function broadcastOn(): array
    {
        // Private; also unprefixed so platform admins (no tenant context) receive it
        return BroadcastChannelResolver::resolveWithPlatform('support-tickets');
    }

    public function broadcastWith(): array
    {
        $user = $this->ticket->user;

        return [
            'ticket' => [
                'id' => $this->ticket->id,
                'subject' => $this->ticket->subject,
                'status' => $this->ticket->status,
                'category' => $this->ticket->category,
                'priority' => $this->ticket->priority,
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
                'created_at' => $this->ticket->created_at?->toISOString(),
            ],
        ];
    }
}
