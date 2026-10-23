<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions of preview environments in the tenant schema: deployment_id may be empty and
 * preview_environment_id names the preview — the central table gets the same
 * (database/migrations/2026_10_21_170002). Until it ran, a tenant's preview pods are not metered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deployment_sessions') || Schema::hasColumn('deployment_sessions', 'preview_environment_id')) {
            return;
        }

        Schema::table('deployment_sessions', function (Blueprint $table) {
            $table->char('deployment_id', 26)->nullable()->change();
            $table->char('preview_environment_id', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deployment_sessions') || ! Schema::hasColumn('deployment_sessions', 'preview_environment_id')) {
            return;
        }

        Schema::table('deployment_sessions', function (Blueprint $table) {
            $table->dropIndex(['preview_environment_id']);
            $table->dropColumn('preview_environment_id');
        });
    }
};
