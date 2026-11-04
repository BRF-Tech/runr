<?php

use App\Support\Notifications\NotificationUserStatesSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_11_04_100001_create_notification_user_states_table.php:
 * each member's read and deleted state of the organization's shared notifications, in the
 * tenant's schema next to its notifications table (0007). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        NotificationUserStatesSchema::up();
    }

    public function down(): void
    {
        NotificationUserStatesSchema::down();
    }
};
