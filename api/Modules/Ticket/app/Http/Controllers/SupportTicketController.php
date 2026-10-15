<?php

namespace Modules\Ticket\Http\Controllers;

use App\Events\NotificationCreated;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Ticket\Events\SupportTicketCreated;
use Modules\Ticket\Events\SupportTicketRead;
use Modules\Ticket\Events\SupportTicketReplied;
use Modules\Ticket\Http\Concerns\HandlesTicketAttachments;
use Modules\Ticket\Http\Requests\StoreSupportTicketRequest;
use Modules\Ticket\Models\SupportTicket;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportTicketController extends Controller
{
    use HandlesTicketAttachments;

    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($tickets);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $ticket = SupportTicket::create([
            'user_id' => $request->user()->id,
            'subject' => strip_tags($request->subject),
            'description' => strip_tags($request->description),
            'category' => $request->category,
            'priority' => $request->priority,
        ]);

        $paths = $this->storeAttachments($request, $ticket->id);
        if ($paths) {
            $ticket->update(['attachments' => $paths]);
        }

        $ticket->load('user');
        event(new SupportTicketCreated($ticket));
        $this->notifyAdmins($ticket);

        return response()->json($ticket, 201);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        if ($supportTicket->user_id !== $request->user()->id) {
            abort(403);
        }

        return response()->json(
            $supportTicket->load(['publicReplies.user:id,name,email'])
                ->setRelation('replies', $supportTicket->publicReplies)
        );
    }

    public function reply(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        if ($supportTicket->user_id !== $request->user()->id) {
            abort(403);
        }

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

        $reply->load('user:id,name,email');
        event(new SupportTicketReplied($supportTicket, $reply));

        return response()->json($reply, 201);
    }

    public function downloadAttachment(Request $request, SupportTicket $supportTicket, string $filename): StreamedResponse
    {
        if ($supportTicket->user_id !== $request->user()->id) {
            abort(403);
        }

        return $this->serveAttachment($supportTicket->id, $filename);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->where('subject', 'ilike', '%'.$request->subject.'%')
            ->select('id', 'subject', 'status', 'created_at')
            ->limit(5)
            ->get();

        return response()->json($tickets);
    }

    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json([
            'open' => SupportTicket::where('user_id', $userId)->where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('user_id', $userId)->where('status', 'in_progress')->count(),
            'resolved' => SupportTicket::where('user_id', $userId)->where('status', 'resolved')->count(),
        ]);
    }

    public function markRead(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        if ($supportTicket->user_id !== $request->user()->id) {
            abort(403);
        }
        $supportTicket->update(['user_read_at' => now()]);
        event(new SupportTicketRead($supportTicket));

        return response()->json(['ok' => true]);
    }

    /**
     * Who works the support queue: the platform administrators (users.is_admin, a column the
     * Tenancy module adds) — in a single-tenant install, without that column, the members whose
     * role holds tickets.manage (opening a ticket answered 500 there).
     *
     * @return Collection<int, User>
     */
    protected function supportStaff(): Collection
    {
        $users = new User;
        if (Schema::connection($users->getConnectionName())->hasColumn($users->getTable(), 'is_admin')) {
            return User::where('is_admin', true)->get();
        }

        $rbac = app(Rbac::class);

        return User::all()->filter(fn (User $user) => $rbac->allows($user, 'tickets.manage'))->values();
    }

    protected function notifyAdmins(SupportTicket $ticket): void
    {
        $this->supportStaff()->each(function (User $admin) use ($ticket) {
            $notif = Notification::create([
                'user_id' => $admin->id,
                'type' => 'ticket.created',
                'level' => 'info',
                'title' => __('api.tickets.created_title', ['subject' => $ticket->subject]),
                'data' => ['ticket_id' => $ticket->id],
            ]);
            event(new NotificationCreated($notif));
        });
    }
}
