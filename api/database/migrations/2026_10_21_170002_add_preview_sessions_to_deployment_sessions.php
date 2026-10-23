<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions of preview environments (docs/billing.md#metering-and-the-fixed-bill): a preview pod's
 * session belongs to its preview (preview_environment_id), not to a deployment — previews have
 * none, so deployment_id may be empty. Tenant schemas:
 * TenantMigrations/0075_add_preview_sessions_to_deployment_sessions.php.
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
