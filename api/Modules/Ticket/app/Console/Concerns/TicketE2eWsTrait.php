<?php

namespace Modules\Ticket\Console\Concerns;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Modules\Ticket\Events\SupportTicketCreated;
use Modules\Ticket\Events\SupportTicketInternalNote;
use Modules\Ticket\Events\SupportTicketRead;
use Modules\Ticket\Events\SupportTicketReplied;
use Modules\Ticket\Models\SupportTicket;

trait TicketE2eWsTrait
{
    protected function runWsTests(array &$results): void
    {
        $ticket = SupportTicket::find($this->ticketId);
        if (! $ticket) {
            $results[] = ['label' => 'ws: (skipped — no ticket)', 'passed' => false, 'message' => 'ticketId not set', 'time' => 0];

            return;
        }

        $reply = $ticket->replies()->first()
            ?? $ticket->replies()->create(['user_id' => $ticket->user_id, 'message' => 'ws-test', 'is_staff' => false]);

        $results[] = $this->runTest('ws: SupportTicketCreated broadcastAs', function () use ($ticket) {
            $event = new SupportTicketCreated($ticket);
            $this->assert($event->broadcastAs() === 'SupportTicketCreated', 'broadcastAs must be SupportTicketCreated');
        });

        // Every ticket channel is private (docs/security.md, POST /api/broadcasting/auth); the
        // unprefixed one is there for platform admins, who have no tenant context
        $results[] = $this->runTest('ws: SupportTicketCreated unprefixed private channel included', function () use ($ticket) {
            $this->assertPrivateChannels((new SupportTicketCreated($ticket))->broadcastOn(), 'support-tickets', 'SupportTicketCreated');
        });

        $results[] = $this->runTest('ws: SupportTicketReplied broadcastAs', function () use ($ticket, $reply) {
            $event = new SupportTicketReplied($ticket, $reply);
            $this->assert($event->broadcastAs() === 'SupportTicketReplied', 'broadcastAs must be SupportTicketReplied');
        });

        $results[] = $this->runTest('ws: SupportTicketReplied unprefixed private channel included', function () use ($ticket, $reply) {
            $this->assertPrivateChannels((new SupportTicketReplied($ticket, $reply))->broadcastOn(), "support-ticket.{$ticket->id}", 'SupportTicketReplied');
        });

        $results[] = $this->runTest('ws: SupportTicketRead broadcastAs', function () use ($ticket) {
            $event = new SupportTicketRead($ticket);
            $this->assert($event->broadcastAs() === 'SupportTicketRead', 'broadcastAs must be SupportTicketRead');
        });

        $results[] = $this->runTest('ws: SupportTicketRead unprefixed private channel included', function () use ($ticket) {
            $this->assertPrivateChannels((new SupportTicketRead($ticket))->broadcastOn(), "support-ticket.{$ticket->id}", 'SupportTicketRead');
        });

        // Event::assertDispatched() needs PHPUnit, which the API image does not ship: read the
        // fake's record instead
        $results[] = $this->runTest('ws: SupportTicketCreated dispatched on store', function () use ($ticket) {
            $events = Event::fake([SupportTicketCreated::class]);
            $newTicket = SupportTicket::create([
                'user_id' => $ticket->user_id,
                'subject' => 'WS dispatch test',
                'description' => 'WS dispatch test',
                'category' => 'other',
                'priority' => 'low',
            ]);
            event(new SupportTicketCreated($newTicket));
            $this->assert($events->dispatched(SupportTicketCreated::class)->isNotEmpty(), 'SupportTicketCreated was not dispatched');
            $newTicket->delete();
        });

        $results[] = $this->runTest('ws: SupportTicketReplied dispatched on reply', function () use ($ticket) {
            $events = Event::fake([SupportTicketReplied::class]);
            $testReply = $ticket->replies()->create([
                'user_id' => $ticket->user_id, 'message' => 'ws reply test', 'is_staff' => false,
            ]);
            event(new SupportTicketReplied($ticket, $testReply));
            $this->assert($events->dispatched(SupportTicketReplied::class)->isNotEmpty(), 'SupportTicketReplied was not dispatched');
            $testReply->delete();
        });

        $results[] = $this->runTest('ws: SupportTicketCreated broadcastWith contains ticket data', function () use ($ticket) {
            $payload = (new SupportTicketCreated($ticket))->broadcastWith();
            $this->assert(isset($payload['ticket']['id']), 'broadcastWith must contain ticket.id');
            $this->assert(isset($payload['ticket']['subject']), 'broadcastWith must contain ticket.subject');
        });

        $results[] = $this->runTest('ws: SupportTicketReplied broadcastWith contains reply data', function () use ($ticket, $reply) {
            $payload = (new SupportTicketReplied($ticket, $reply))->broadcastWith();
            $this->assert(isset($payload['reply']['id']), 'broadcastWith must contain reply.id');
            $this->assert(isset($payload['reply']['message']), 'broadcastWith must contain reply.message');
        });

        // Internal notes are for ticket managers: their own private channel, never the one the
        // ticket's author listens on
        $results[] = $this->runTest('ws: SupportTicketInternalNote staff-only channel', function () use ($ticket, $reply) {
            $event = new SupportTicketInternalNote($ticket, $reply);
            $channels = $event->broadcastOn();
            $names = array_map(fn ($ch) => $ch->name, $channels);
            $internal = "private-support-ticket.{$ticket->id}.internal";
            $this->assert(count($channels) === 1, 'SupportTicketInternalNote must broadcast on exactly 1 channel');
            $this->assert($channels[0] instanceof PrivateChannel, 'SupportTicketInternalNote must use a private channel');
            $this->assert($names === [$internal], "SupportTicketInternalNote must use {$internal}. Got: ".implode(', ', $names));
            $this->assert($event->broadcastAs() === 'SupportTicketInternalNote', 'broadcastAs must be SupportTicketInternalNote');
            $payload = $event->broadcastWith();
            $this->assert($payload['reply']['is_internal'] === true, 'broadcastWith must set is_internal=true');
        });
    }

    /** Every channel is private, and the unprefixed private one is among them. */
    private function assertPrivateChannels(array $channels, string $base, string $event): void
    {
        $names = array_map(fn ($ch) => $ch->name, $channels);
        foreach ($channels as $channel) {
            $this->assert($channel instanceof PrivateChannel, "{$event} broadcasts on a public channel {$channel->name}");
        }
        $this->assert(
            in_array("private-{$base}", $names, true),
            "{$event} must include the private channel private-{$base}. Got: ".implode(', ', $names)
        );
    }
}
