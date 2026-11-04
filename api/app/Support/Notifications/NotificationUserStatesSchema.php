<?php

namespace App\Support\Notifications;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema of each member's read and deleted state of the shared notifications (the ones without a
 * user_id, e.g. resource thresholds), shared by the central migration (2026_11_04_100001) and the
 * tenant migration (0110):
 *
 *  - notification_user_states — one row per notification and user, written when that user marks a
 *    shared notification read or deletes it (NotificationController); the other members' view
 *    stays as it was. A user's own notifications keep their state in notifications.read_at and
 *    deleted_at. The rows go with their notification (cascade); user_id has no foreign key, like
 *    notifications.user_id (a tenant schema reads the central users).
 *
 * Additive only: no existing row changes.
 */
final class NotificationUserStatesSchema
{
    public const TABLE = 'notification_user_states';

    public static function up(): void
    {
        // hasTable() looks at the current schema only: a tenant's, not the central public one
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public static function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
