<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ticket\Events\SupportTicketRead;
use Modules\Ticket\Events\SupportTicketReplied;
use Modules\Ticket\Http\Controllers\SupportTicketController;
use Modules\Ticket\Models\SupportTicket;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tenant-aware support ticket controller.
 * Removes user_id filter — all tenant members see all tenant tickets.
 * Schema-per-tenant (search_path) provides cross-tenant isolation.
 * CRITICAL: Tenant users cannot change status — no updateStatus method.
 */
class TenancySupportTicketController extends SupportTicketController
{
    /** List all tickets in current tenant (not just user's own). */
    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::with('user:id,name,email')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($tickets);
    }

    /** Any tenant member can view any tenant ticket (internal notes filtered). */
    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $supportTicket->load('publicReplies');

        // Staff reply users may be in central schema — resolve them
        foreach ($supportTicket->publicReplies as $reply) {
            $user = User::select('id', 'name', 'email')->find($reply->user_id)
                ?? User::on('central')->select('id', 'name', 'email')->find($reply->user_id);
            $reply->setRelation('user', $user);
        }

        $supportTicket->setRelation('replies', $supportTicket->publicReplies);

        return response()->json($supportTicket);
    }

    /** Any tenant member can reply to any tenant ticket. */
    public function reply(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,pdf', 'max:5120'],
        ]);

        $paths = $this->storeAttachments($request, $supportTicket->id);

        $reply = $supportTicket->replies()->create([
            'user_id' => $request->user()->id,
            'message' => strip_tags($request->message),
            'is_staff' => false,
            'attachments' => $paths ?: null,
        ]);

        event(new SupportTicketReplied($supportTicket, $reply));
        $this->notifyAdminsOnReply($supportTicket, $reply);

        return response()->json($reply->load('user:id,name,email'), 201);
    }

    /**
     * Download attachment — any tenant member can access the files recorded on a ticket of the
     * tenant (not those of internal notes).
     */
    public function downloadAttachment(Request $request, SupportTicket $supportTicket, string $filename): StreamedResponse
    {
        return $this->serveAttachment($supportTicket, $filename);
    }

    /** Suggestions: search all tenant tickets (no user_id filter). */
    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $tickets = SupportTicket::whereIn('status', ['open', 'in_progress'])
            ->where('subject', 'ilike', '%'.$request->subject.'%')
            ->select('id', 'subject', 'status', 'created_at')
            ->limit(5)
            ->get();

        return response()->json($tickets);
    }

    /** Stats for all tickets in this tenant. */
    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'open' => SupportTicket::where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
            'resolved' => SupportTicket::where('status', 'resolved')->count(),
        ]);
    }

    /** Mark ticket as read by the tenant user (no user_id check — tenant isolation via schema). */
    public function markRead(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $supportTicket->update(['user_read_at' => now()]);
        event(new SupportTicketRead($supportTicket));

        return response()->json(['ok' => true]);
    }

    protected function notifyAdmins(SupportTicket $ticket): void
    {
        $tenantId = tenancy()->tenant?->id;
        tenancy()->central(function () use ($ticket, $tenantId) {
            User::where('is_admin', true)->each(function (User $admin) use ($ticket, $tenantId) {
                $notif = Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'ticket.created',
                    'level' => 'info',
                    'title' => __('api.tickets.created_title', ['subject' => $ticket->subject]),
                    'data' => ['ticket_id' => $ticket->id, 'tenant_id' => $tenantId],
                ]);
                event(new NotificationCreated($notif));
            });
        });
    }

    protected function notifyAdminsOnReply(SupportTicket $ticket, $reply): void
    {
        $tenantId = tenancy()->tenant?->id;
        tenancy()->central(function () use ($ticket, $reply, $tenantId) {
            User::where('is_admin', true)->each(function (User $admin) use ($ticket, $reply, $tenantId) {
                $notif = Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'ticket.reply',
                    'level' => 'info',
                    'title' => __('api.tickets.reply_title', ['subject' => $ticket->subject]),
                    'data' => ['ticket_id' => $ticket->id, 'reply_id' => $reply->id, 'tenant_id' => $tenantId],
                ]);
                event(new NotificationCreated($notif));
            });
        });
    }
}
