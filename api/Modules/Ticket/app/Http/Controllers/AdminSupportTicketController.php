<?php

namespace Modules\Ticket\Http\Controllers;

use App\Events\NotificationCreated;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ticket\Events\SupportTicketInternalNote;
use Modules\Ticket\Events\SupportTicketRead;
use Modules\Ticket\Events\SupportTicketReplied;
use Modules\Ticket\Events\SupportTicketStatusChanged;
use Modules\Ticket\Http\Concerns\HandlesTicketAttachments;
use Modules\Ticket\Models\SupportTicket;
use Modules\Ticket\Support\TicketStaff;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSupportTicketController extends Controller
{
    use HandlesTicketAttachments;

    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::with(['user:id,name,email', 'assignee:id,name,email'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->when($request->assigned_to, fn ($q, $a) => $q->where('assigned_to', $a))
            ->when($request->search, fn ($q, $s) => $q->where('subject', 'ilike', "%{$s}%"))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($tickets);
    }

    public function show(int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        return response()->json(
            $ticket->load(['user:id,name,email', 'assignee:id,name,email', 'replies.user:id,name,email'])
        );
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'status' => ['required', 'string', 'in:open,in_progress,resolved,closed'],
        ]);

        $ticket->update(['status' => $request->status]);
        event(new SupportTicketStatusChanged($ticket));

        $notif = Notification::create([
            'user_id' => $ticket->user_id,
            'type' => 'ticket.status',
            'level' => 'info',
            'title' => __('api.tickets.status_title', ['subject' => $ticket->subject]),
            'data' => ['ticket_id' => $ticket->id, 'status' => $ticket->status],
        ]);
        event(new NotificationCreated($notif));

        return response()->json($ticket);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,pdf', 'max:5120'],
        ]);

        $paths = $this->storeAttachments($request, $ticket->id);

        $reply = $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'message' => strip_tags($request->message),
            'is_staff' => true,
            'is_internal' => (bool) $request->is_internal,
            'attachments' => $paths ?: null,
        ]);

        $reply->load('user:id,name,email');
        if ($reply->is_internal) {
            event(new SupportTicketInternalNote($ticket, $reply));
        } else {
            event(new SupportTicketReplied($ticket, $reply));
        }

        return response()->json($reply, 201);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $ticket->update(['assigned_to' => $request->assigned_to]);

        return response()->json(
            $ticket->load('assignee:id,name,email')
        );
    }

    public function updateTags(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $request->validate([
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
        ]);

        $tags = $request->tags ? array_map('strip_tags', $request->tags) : null;
        $ticket->update(['tags' => $tags]);

        return response()->json($ticket);
    }

    public function downloadAttachment(Request $request, int $id, string $filename): StreamedResponse
    {
        // Staff see internal notes: their files too
        return $this->serveAttachment(SupportTicket::findOrFail($id), $filename, includeInternal: true);
    }

    /** Assignee choices: the members who work the support queue (tickets.manage). */
    public function staff(): JsonResponse
    {
        return response()->json(['data' => TicketStaff::present(TicketStaff::users())]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => SupportTicket::count(),
            'open' => SupportTicket::where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
            'resolved' => SupportTicket::where('status', 'resolved')->count(),
        ]);
    }

    public function adminMarkRead(Request $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);
        $ticket->update(['admin_read_at' => now()]);
        event(new SupportTicketRead($ticket));

        return response()->json(['ok' => true]);
    }
}
