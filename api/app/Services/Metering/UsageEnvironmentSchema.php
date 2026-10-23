<?php

namespace App\Services\Metering;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * usage_records.environment_name: the name of the project environment a record's deployment ran in
 * (docs/environments.md#stopping-deleting-metering), written with environment_id when the day is
 * metered (UsageAggregator). A bill line names the environment its usage came from, also after
 * the environment was deleted (its row is gone, the name stays on its records); null: the
 * project's own workload (production), or a record metered before the column existed — the bill
 * then reads the name from project_environments.
 *
 * One definition for the central migration (database/migrations/2026_10_22_130001_…) and the
 * tenant copy (Modules/Tenancy/Database/TenantMigrations/0087_…); the central table also holds
 * the copies of the tenants' records (UsageAggregator::mirror()), which carry the name along.
 */
final class UsageEnvironmentSchema
{
    public static function up(): void
    {
        if (Schema::hasTable('usage_records') && ! Schema::hasColumn('usage_records', 'environment_name')) {
            Schema::table('usage_records', function (Blueprint $table) {
                $table->string('environment_name', 20)->nullable();
            });
        }
    }

    public static function down(): void
    {
        if (Schema::hasTable('usage_records') && Schema::hasColumn('usage_records', 'environment_name')) {
            Schema::table('usage_records', fn (Blueprint $table) => $table->dropColumn('environment_name'));
        }
    }
}
