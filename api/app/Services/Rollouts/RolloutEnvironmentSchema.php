<?php

namespace App\Services\Rollouts;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deployment_rollouts.environment_id (docs/deploy-strategies.md#environments): the environment
 * whose workload a rollout moves the traffic of (null: the project's own workload). Defined once
 * for the central migration (database/migrations/2026_10_22_100002_add_environment_to_deployment_rollouts.php)
 * and the tenant copy (Modules/Tenancy/Database/TenantMigrations/0088_add_environment_to_deployment_rollouts.php).
 */
final class RolloutEnvironmentSchema
{
    public static function up(): void
    {
        if (! Schema::hasTable('deployment_rollouts') || Schema::hasColumn('deployment_rollouts', 'environment_id')) {
            return;
        }

        Schema::table('deployment_rollouts', function (Blueprint $table) {
            $table->string('environment_id', 26)->nullable()->index();
        });
    }

    public static function down(): void
    {
        if (! Schema::hasTable('deployment_rollouts') || ! Schema::hasColumn('deployment_rollouts', 'environment_id')) {
            return;
        }

        Schema::table('deployment_rollouts', function (Blueprint $table) {
            $table->dropIndex(['environment_id']);
            $table->dropColumn('environment_id');
        });
    }
}
