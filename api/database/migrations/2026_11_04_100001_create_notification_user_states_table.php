<?php

use App\Support\Notifications\NotificationUserStatesSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Read and deleted per member for the shared notifications (no user_id, e.g. resource
 * thresholds): notification_user_states holds one row per notification and user, so one member's
 * "mark all as read" or "delete all" no longer changes what the others see. Additive only.
 *
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0110_create_notification_user_states_table.php
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
