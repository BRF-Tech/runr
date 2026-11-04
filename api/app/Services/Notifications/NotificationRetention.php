<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationUserState;
use App\Models\User;
use App\Services\GitDeploy\TenantContext;
use App\Support\Notifications\NotificationUserStatesSchema;
use App\Support\PlatformConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retention of the in-app notifications (config/notifications.php). The daily prune
 * (PruneNotificationsJob) deletes, in the central schema and in every organization's schema:
 *
 *  - the notifications created more than retentionDays() ago (0: none) — a user's own and the
 *    shared ones, read or not, deleted or not;
 *  - the notifications of users that no longer exist and their read and deleted state of the
 *    shared ones, whatever the retention (left behind by account deletions before 0.6.5; since
 *    then forgetUsers() runs when an account is deleted).
 *
 * Every delete is for good (a soft-deleted notification too), CHUNK rows per statement; the
 * members' state rows of a shared notification go with it (notification_user_states cascades).
 */
final class NotificationRetention
{
    /** Rows per delete statement: each statement and its row locks stay short on a large table */
    public const CHUNK = 1000;

    /** User ids per lookup of the central users table */
    private const USER_CHUNK = 500;

    public function retentionDays(): int
    {
        return max(0, (int) config('notifications.retention_days', 90));
    }

    /**
     * Prunes the central schema and, in a multi-tenant install, every organization's schema.
     *
     * @return array{expired: int, users: int} notifications past the retention; notifications and
     *                                         state rows of deleted users
     */
    public function prune(): array
    {
        $removed = $this->pruneHere();

        if (TenantContext::enabled()) {
            TenantContext::each(function () use (&$removed) {
                foreach ($this->pruneHere() as $kind => $count) {
                    $removed[$kind] += $count;
                }
            });
        }

        return $removed;
    }

    /**
     * The notifications of the users $userIds — their own ones, soft-deleted ones too — and their
     * read and deleted state of the shared ones, on $connection (null: the current schema).
     *
     * @param  list<int|string>  $userIds
     * @return int rows deleted
     */
    public function forgetUsers(array $userIds, ?string $connection = null): int
    {
        // notifications.user_id is a string column, notification_user_states.user_id an integer
        $ids = array_values(array_unique(array_map(fn (int|string $id) => (string) $id, $userIds)));
        if ($ids === [] || ! $this->hasTable($connection, (new Notification)->getTable())) {
            return 0;
        }

        $states = $this->hasTable($connection, NotificationUserStatesSchema::TABLE);
        $removed = 0;
        foreach (array_chunk($ids, self::USER_CHUNK) as $chunk) {
            $removed += $this->deleteChunked(Notification::on($connection)->withTrashed()->whereIn('user_id', $chunk));

            $numeric = array_values(array_filter($chunk, 'ctype_digit'));
            if ($states && $numeric !== []) {
                $removed += $this->deleteChunked(NotificationUserState::on($connection)->whereIn('user_id', array_map('intval', $numeric)));
            }
        }

        return $removed;
    }

    /** @return array{expired: int, users: int} the current schema's prune (prune()) */
    private function pruneHere(): array
    {
        if (! $this->hasTable(null, (new Notification)->getTable())) {
            return ['expired' => 0, 'users' => 0];
        }

        $expired = 0;
        $days = $this->retentionDays();
        if ($days > 0) {
            $expired = $this->deleteChunked(Notification::query()->withTrashed()->where('created_at', '<', now()->subDays($days)));
        }

        return ['expired' => $expired, 'users' => $this->forgetUsers($this->deletedUsers())];
    }

    /**
     * Users the current schema's notifications and state rows name whose account no longer exists
     * (the central users table: an organization's schema names the platform's accounts). A user id
     * is an integer: another value names no account and is left alone.
     *
     * @return list<string>
     */
    private function deletedUsers(): array
    {
        /** @var Collection<int, mixed> $named */
        $named = Notification::query()->withTrashed()->whereNotNull('user_id')->distinct()->pluck('user_id');
        if ($this->hasTable(null, NotificationUserStatesSchema::TABLE)) {
            $named = $named->merge(NotificationUserState::query()->distinct()->pluck('user_id'));
        }
        $named = $named->map(fn ($id) => (string) $id)->filter(fn (string $id) => ctype_digit($id))->unique()->values();

        $users = DB::connection(PlatformConnection::name())->table((new User)->getTable());
        $existing = $named->chunk(self::USER_CHUNK)
            ->flatMap(fn (Collection $ids) => (clone $users)->whereIn('id', $ids->all())->pluck('id'))
            ->map(fn ($id) => (string) $id)
            ->all();

        return $named->diff($existing)->values()->all();
    }

    /**
     * Whether the schema of $connection (null: the current one) has $table. Without it nothing is
     * read or deleted there: on PostgreSQL a table an organization's schema lacks (such as
     * notification_user_states before its tenant migration 0110) would resolve to the central
     * one (search_path "tenant_…", public).
     */
    private function hasTable(?string $connection, string $table): bool
    {
        return Schema::connection($connection)->hasTable($table);
    }

    /**
     * Deletes for good what $query selects, CHUNK rows per statement (a notification's state rows
     * cascade).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return int rows deleted
     */
    private function deleteChunked(Builder $query): int
    {
        $model = $query->getModel();
        $deleted = 0;

        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck($model->getKeyName())->all();
            if ($ids === []) {
                break;
            }

            // A real DELETE without the soft-delete scope: what forceDelete() runs for Notification
            $deleted += $model->newQueryWithoutScopes()->whereKey($ids)->toBase()->delete();
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }
}
