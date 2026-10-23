<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deployment_sessions.last_seen_at: when the region agent last reported the session's pod as
 * running (its heartbeat). A session whose heartbeats stop is closed as of then
 * (SessionTrackingService::closeStale); sessions of agents without heartbeats keep it null.
 * Tenant schemas: TenantMigrations/0074_add_last_seen_at_to_deployment_sessions.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deployment_sessions') || Schema::hasColumn('deployment_sessions', 'last_seen_at')) {
            return;
        }

        Schema::table('deployment_sessions', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->index(['ended_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deployment_sessions') || ! Schema::hasColumn('deployment_sessions', 'last_seen_at')) {
            return;
        }

        Schema::table('deployment_sessions', function (Blueprint $table) {
            $table->dropIndex(['ended_at', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
