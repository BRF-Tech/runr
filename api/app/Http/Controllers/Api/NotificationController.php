<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationUserState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The notification centre of the signed-in user: their own notifications and the shared ones (no
 * user_id, e.g. resource thresholds). Reading and deleting change the user's view only: an own
 * notification its read_at or soft delete, a shared one this user's row of
 * notification_user_states, so the other members still see it unread and listed.
 */
class NotificationController extends Controller
{
    /** Shared notifications whose state markAllRead / destroyAll writes per statement */
    private const STATE_CHUNK = 500;

    /**
     * List the signed-in user's notifications
     *
     * Paginated, newest first, with an optional unread_only filter. Shared notifications (without
     * a user, e.g. resource thresholds) are included with this user's read state, unless this user
     * deleted them.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId();
        $query = $this->withUserReadAt($this->visibleQuery($userId), $userId);

        if ($request->boolean('unread_only')) {
            $this->whereUnread($query, $userId);
        }

        $notifications = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));
        $notifications->getCollection()->each(fn (Notification $n) => $this->applyUserReadAt($n));

        return response()->json($notifications);
    }

    /**
     * Count the signed-in user's unread notifications
     *
     * For the header badge. A shared notification counts until this user reads or deletes it.
     */
    public function unreadCount(): JsonResponse
    {
        $userId = $this->userId();
        $count = $this->whereUnread($this->visibleQuery($userId), $userId)->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a notification as read
     *
     * For the signed-in user only: a shared notification stays unread for the other members.
     */
    public function markRead(string $id): JsonResponse
    {
        $userId = $this->userId();
        /** @var Notification $notification */
        $notification = $this->withUserReadAt($this->visibleQuery($userId), $userId)->findOrFail($id);
        $this->applyUserReadAt($notification);

        if ($notification->read_at === null) {
            $now = now();
            if ($notification->user_id === null) {
                $this->writeStates(collect([$notification->id]), $userId, 'read_at', $now);
                $notification->setAttribute('read_at', $now);
            } else {
                $notification->update(['read_at' => $now]);
            }
        }

        return response()->json($notification);
    }

    /**
     * Mark all notifications the signed-in user sees as read
     *
     * The shared ones (without a user) for this user only: the other members still see them unread.
     */
    public function markAllRead(): JsonResponse
    {
        $userId = $this->userId();
        $now = now();

        $this->baseQuery()->where('user_id', $userId)->whereNull('read_at')->update(['read_at' => $now]);
        $this->writeStatesOf($this->whereUnread($this->sharedQuery($userId), $userId), $userId, 'read_at', $now);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Delete a notification
     *
     * A soft delete of the user's own notification; a shared one (without a user) leaves this
     * user's list only.
     */
    public function destroy(string $id): JsonResponse
    {
        $userId = $this->userId();
        /** @var Notification $notification */
        $notification = $this->visibleQuery($userId)->findOrFail($id);

        if ($notification->user_id === null) {
            $this->writeStates(collect([$notification->id]), $userId, 'deleted_at', now());
        } else {
            $notification->delete();
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Delete all notifications the signed-in user sees
     *
     * A soft delete of the user's own notifications; the shared ones (without a user) leave this
     * user's list only, the other members keep them.
     */
    public function destroyAll(): JsonResponse
    {
        $userId = $this->userId();

        $this->baseQuery()->where('user_id', $userId)->delete();
        $this->writeStatesOf($this->sharedQuery($userId), $userId, 'deleted_at', now());

        return response()->json(['status' => 'ok']);
    }

    /**
     * The signed-in user's notifications and the shared ones (no user_id: e.g. resource
     * thresholds) — never another user's (a job's result for whoever started it, a support
     * ticket for each admin), just as NotificationCreated pushes them on the user's channel only.
     * The shared ones the user deleted are still in it (visibleQuery() leaves them out).
     *
     * @return Builder<Notification>
     */
    protected function baseQuery(): Builder
    {
        $userId = request()->user()?->getAuthIdentifier();

        return Notification::query()->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }

    /**
     * baseQuery() without the shared notifications the user deleted.
     *
     * @return Builder<Notification>
     */
    protected function visibleQuery(int|string $userId): Builder
    {
        return $this->baseQuery()->whereDoesntHave('userStates', fn (Builder $state) => $state
            ->where($state->qualifyColumn('user_id'), $userId)
            ->whereNotNull($state->qualifyColumn('deleted_at')));
    }

    /**
     * The shared notifications the user sees.
     *
     * @return Builder<Notification>
     */
    private function sharedQuery(int|string $userId): Builder
    {
        return $this->visibleQuery($userId)->whereNull('user_id');
    }

    /**
     * Unread for the user: an own notification by its read_at; a shared one by the user's state
     * and by its read_at, which only a read before 0.6.5 set (for everyone).
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private function whereUnread(Builder $query, int|string $userId): Builder
    {
        return $query->whereNull($query->qualifyColumn('read_at'))
            ->whereDoesntHave('userStates', fn (Builder $state) => $state
                ->where($state->qualifyColumn('user_id'), $userId)
                ->whereNotNull($state->qualifyColumn('read_at')));
    }

    /**
     * Adds user_read_at, the user's read time of a shared notification (applyUserReadAt()).
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private function withUserReadAt(Builder $query, int|string $userId): Builder
    {
        $state = NotificationUserState::query();

        return $query->addSelect(['user_read_at' => $state->select($state->qualifyColumn('read_at'))
            ->whereColumn($state->qualifyColumn('notification_id'), $query->qualifyColumn('id'))
            ->where($state->qualifyColumn('user_id'), $userId)
            ->limit(1)]);
    }

    /** read_at as the user sees it: a shared notification with their own read time. Never saved. */
    private function applyUserReadAt(Notification $notification): void
    {
        $userReadAt = $notification->getAttribute('user_read_at');
        $notification->offsetUnset('user_read_at');

        if ($notification->user_id === null && $notification->read_at === null && $userReadAt !== null) {
            $notification->setAttribute('read_at', $userReadAt);
        }
    }

    /**
     * writeStates() for every notification of $shared, a chunk at a time.
     *
     * @param  Builder<Notification>  $shared
     */
    private function writeStatesOf(Builder $shared, int|string $userId, string $column, Carbon $at): void
    {
        $id = $shared->qualifyColumn('id');

        $shared->select($id)->toBase()->chunkById(
            self::STATE_CHUNK,
            fn (Collection $rows) => $this->writeStates($rows->pluck('id'), $userId, $column, $at),
            $id,
            'id',
        );
    }

    /**
     * Sets $column (read_at or deleted_at) of the user's state of these shared notifications: a
     * new row for each that has none, the other column of an existing row stays.
     *
     * @param  Collection<int, string>  $ids
     */
    private function writeStates(Collection $ids, int|string $userId, string $column, Carbon $at): void
    {
        $rows = $ids->map(fn (string $id) => ['notification_id' => $id, 'user_id' => $userId, $column => $at])->all();

        NotificationUserState::query()->upsert($rows, ['notification_id', 'user_id'], [$column]);
    }

    /** The signed-in user's id: every route of the controller requires a sign-in. */
    private function userId(): int|string
    {
        $id = request()->user()?->getAuthIdentifier();
        abort_if($id === null || $id === '', 401);

        return $id;
    }
}
