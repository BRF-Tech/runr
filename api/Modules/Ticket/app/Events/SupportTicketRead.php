<?php

namespace Modules\Ticket\Events;

use App\Support\BroadcastChannelResolver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Ticket\Models\SupportTicket;

class SupportTicketRead implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly SupportTicket $ticket) {}

    public function broadcastAs(): string
    {
        return 'SupportTicketRead';
    }

    public function broadcastOn(): array
    {
        // Private; also unprefixed so platform admins (no tenant context) receive it
        return BroadcastChannelResolver::resolveWithPlatform("support-ticket.{$this->ticket->id}");
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'admin_read_at' => $this->ticket->admin_read_at?->toISOString(),
            'user_read_at' => $this->ticket->user_read_at?->toISOString(),
        ];
    }
}
