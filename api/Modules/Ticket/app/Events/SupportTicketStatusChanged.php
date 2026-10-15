<?php

namespace Modules\Ticket\Events;

use App\Support\BroadcastChannelResolver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Ticket\Models\SupportTicket;

class SupportTicketStatusChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly SupportTicket $ticket) {}

    public function broadcastAs(): string
    {
        return 'SupportTicketStatusChanged';
    }

    public function broadcastOn(): array
    {
        // Ticket-specific channel (detail modal) + list channel (live list update); private, and
        // also unprefixed so platform admins (no tenant context) receive them
        return array_merge(
            BroadcastChannelResolver::resolveWithPlatform("support-ticket.{$this->ticket->id}"),
            BroadcastChannelResolver::resolveWithPlatform('support-tickets'),
        );
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'status' => $this->ticket->status,
        ];
    }
}
