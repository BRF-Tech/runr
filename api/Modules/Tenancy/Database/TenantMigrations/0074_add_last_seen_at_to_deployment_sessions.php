<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deployment_sessions.last_seen_at (the region agent's heartbeat, SessionTrackingService) in the
 * tenant schema — the central table gets the same column (database/migrations/2026_10_21_170001).
 * Until it ran, a tenant's sessions are closed by the 30-minute rule of agents without heartbeats.
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
