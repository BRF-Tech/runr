<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List notifications (paginated, optional unread_only filter).
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery();

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        $notifications = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($notifications);
    }

    /**
     * Get unread notification count (for header badge).
     */
    public function unreadCount(): JsonResponse
    {
        $count = $this->baseQuery()->unread()->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(string $id): JsonResponse
    {
        $notification = $this->baseQuery()->findOrFail($id);
        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(): JsonResponse
    {
        $this->baseQuery()->unread()->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Soft-delete a single notification.
     */
    public function destroy(string $id): JsonResponse
    {
        $notification = $this->baseQuery()->findOrFail($id);
        $notification->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Soft-delete all notifications.
     */
    public function destroyAll(): JsonResponse
    {
        $this->baseQuery()->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * The signed-in user's notifications and the shared ones (no user_id: e.g. resource
     * thresholds) — never another user's (a job's result for whoever started it, a support
     * ticket for each admin), just as NotificationCreated pushes them on the user's channel only.
     */
    protected function baseQuery(): Builder
    {
        $userId = request()->user()?->getAuthIdentifier();

        return Notification::query()->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }
}
