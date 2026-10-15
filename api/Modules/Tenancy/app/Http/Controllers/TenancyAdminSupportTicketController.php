<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Events\NotificationCreated;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Ticket\Events\SupportTicketInternalNote;
use Modules\Ticket\Events\SupportTicketRead;
use Modules\Ticket\Events\SupportTicketReplied;
use Modules\Ticket\Events\SupportTicketStatusChanged;
use Modules\Ticket\Http\Concerns\HandlesTicketAttachments;
use Modules\Ticket\Models\SupportTicket;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cross-tenant admin support ticket controller.
 * Uses HandlesTicketAttachments trait for storeAttachments/serveAttachment helpers.
 * No route-model binding — uses plain {id} to avoid central-schema 404s.
 */
class TenancyAdminSupportTicketController extends Controller
{
    use HandlesTicketAttachments;

    public function index(Request $request): JsonResponse
    {
        if (tenancy()->tenant) {
            return $this->indexForCurrentTenant($request);
        }

        return $this->indexAcrossTenants($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = $this->resolveTicket($id, $request->query('tenant_id'));

        $ticket->load(['replies', 'assignee:id,name,email']);
        $this->resolveUserFromCentral($ticket);
        $this->resolveReplyUsers($ticket);

        return response()->json($ticket);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $ticket = $this->resolveTicket($id, $request->query('tenant_id'));

        $request->validate([
            'status' => ['required', 'string', 'in:open,in_progress,resolved,closed'],
        ]);

        $tenantId = tenancy()->tenant?->id;
        $ticket->update(['status' => $request->status]);
        event(new SupportTicketStatusChanged($ticket));
        $this->notifyStatusChange($ticket, $tenantId);

        return response()->json($ticket);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $ticket = $this->resolveTicket($id, $request->query('tenant_id'));

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

        $reply->setRelation('user', User::on('central')->select('id', 'name', 'email')->find($reply->user_id));

        if ($reply->is_internal) {
            event(new SupportTicketInternalNote($ticket, $reply));
        } else {
            event(new SupportTicketReplied($ticket, $reply));
            $this->notifyTicketOwner($ticket, $reply);
        }

        return response()->json($reply, 201);
    }

    public function adminMarkRead(Request $request, int $id): JsonResponse
    {
        $ticket = $this->resolveTicket($id, $request->query('tenant_id'));
        $ticket->update(['admin_read_at' => now()]);
        event(new SupportTicketRead($ticket));

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $ticket = $this->resolveTicket($id, $request->query('tenant_id'));

        $request->validate([
            'assigned_to' => ['nullable', 'exists:central.users,id'],
        ]);

        $ticket->update(['assigned_to' => $request->assigned_to]);

        if ($request->assigned_to) {
            $assignee = User::on('central')->select('id', 'name', 'email')->find($request->assigned_to);
            $ticket->setRelation('assignee', $assignee);
        }

        return response()->json($ticket->load('assignee:id,name,email'));
    }

    public function updateTags(Request $request, int $id): JsonResponse
    {
        $ticket = $this->resolveTicket($id, $request->query('tenant_id'));

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
        $this->resolveTicket($id, $request->query('tenant_id'));

        return $this->serveAttachment($id, $filename);
    }

    public function stats(): JsonResponse
    {
        if (tenancy()->tenant) {
            return response()->json([
                'total' => SupportTicket::count(),
                'open' => SupportTicket::where('status', 'open')->count(),
                'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
                'resolved' => SupportTicket::where('status', 'resolved')->count(),
            ]);
        }

        return $this->statsAcrossTenants();
    }

    // --- Private helpers ---

    private function indexForCurrentTenant(Request $request): JsonResponse
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

    /**
     * Aggregate tickets from ALL tenants.
     * Converts to arrays before ending tenancy to avoid stale connection errors.
     */
    private function indexAcrossTenants(Request $request): JsonResponse
    {
        $allTickets = [];
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            $tickets = SupportTicket::query()
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->category, fn ($q, $c) => $q->where('category', $c))
                ->when($request->search, fn ($q, $s) => $q->where('subject', 'ilike', "%{$s}%"))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            foreach ($tickets as $ticket) {
                $centralUser = User::on('central')->select('id', 'name', 'email')->find($ticket->user_id);

                $arr = $ticket->toArray();
                $arr['tenant_id'] = $tenant->id;
                $arr['tenant_name'] = $tenant->name;
                $arr['tenant_slug'] = $tenant->slug;
                $arr['user'] = $centralUser ? $centralUser->toArray() : null;

                $allTickets[] = $arr;
            }

            tenancy()->end();
        }

        // Sort by created_at desc
        usort($allTickets, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $page = (int) $request->get('page', 1);
        $perPage = 20;
        $total = count($allTickets);
        $items = array_slice($allTickets, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => array_values($items),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
        ]);
    }

    private function statsAcrossTenants(): JsonResponse
    {
        $stats = ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0];

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);
            $stats['total'] += SupportTicket::count();
            $stats['open'] += SupportTicket::where('status', 'open')->count();
            $stats['in_progress'] += SupportTicket::where('status', 'in_progress')->count();
            $stats['resolved'] += SupportTicket::where('status', 'resolved')->count();
            tenancy()->end();
        }

        return response()->json($stats);
    }

    private function resolveUserFromCentral(SupportTicket $ticket): void
    {
        $user = User::on('central')->select('id', 'name', 'email')->find($ticket->user_id);
        $ticket->setRelation('user', $user);
    }

    /**
     * Resolve reply users — staff replies reference central users,
     * tenant replies reference tenant users. Try tenant first, fallback to central.
     */
    private function resolveReplyUsers(SupportTicket $ticket): void
    {
        foreach ($ticket->replies as $reply) {
            if ($reply->relationLoaded('user') && $reply->user) {
                continue;
            }
            // Try tenant schema first, then central
            $user = User::select('id', 'name', 'email')->find($reply->user_id)
                ?? User::on('central')->select('id', 'name', 'email')->find($reply->user_id);
            $reply->setRelation('user', $user);
        }
    }

    protected function notifyStatusChange(SupportTicket $ticket, ?string $tenantId): void
    {
        $notif = Notification::create([
            'user_id' => $ticket->user_id,
            'type' => 'ticket.status',
            'level' => 'info',
            'title' => __('api.tickets.status_title', ['subject' => $ticket->subject]),
            'data' => ['ticket_id' => $ticket->id, 'status' => $ticket->status, 'tenant_id' => $tenantId],
        ]);
        event(new NotificationCreated($notif));
    }

    protected function notifyTicketOwner(SupportTicket $ticket, $reply): void
    {
        $notif = Notification::create([
            'user_id' => $ticket->user_id,
            'type' => 'ticket.reply',
            'level' => 'info',
            'title' => __('api.tickets.staff_reply_title', ['subject' => $ticket->subject]),
            'data' => ['ticket_id' => $ticket->id, 'reply_id' => $reply->id],
        ]);
        event(new NotificationCreated($notif));
    }

    /**
     * Resolve a ticket by ID, initializing tenant context if needed.
     */
    private function resolveTicket(int $id, ?string $tenantId): SupportTicket
    {
        if (tenancy()->tenant) {
            return SupportTicket::findOrFail($id);
        }

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }

        return SupportTicket::findOrFail($id);
    }
}
