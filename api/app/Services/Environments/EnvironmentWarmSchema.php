<?php

namespace App\Services\Environments;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_environments.warm_scale_applied / _at (docs/scaling.md#timed-warmer): the warm container
 * count the timed warmer last applied to an environment's workload and since when — the
 * environment's counterpart of projects.warm_scale_applied (null while no warmer runs there). It
 * also tells the warmer which environments to let go once their schedule is turned off. Defined
 * once for the central migration
 * (database/migrations/2026_10_22_100003_add_warm_scale_to_project_environments.php) and the tenant
 * copy (Modules/Tenancy/Database/TenantMigrations/0089_add_warm_scale_to_project_environments.php).
 */
final class EnvironmentWarmSchema
{
    public static function up(): void
    {
        if (! Schema::hasTable('project_environments') || Schema::hasColumn('project_environments', 'warm_scale_applied')) {
            return;
        }

        Schema::table('project_environments', function (Blueprint $table) {
            $table->unsignedInteger('warm_scale_applied')->nullable();
            $table->timestamp('warm_scale_applied_at')->nullable();
        });
    }

    public static function down(): void
    {
        if (! Schema::hasTable('project_environments') || ! Schema::hasColumn('project_environments', 'warm_scale_applied')) {
            return;
        }

        Schema::table('project_environments', function (Blueprint $table) {
            $table->dropColumn(['warm_scale_applied', 'warm_scale_applied_at']);
        });
    }
}
