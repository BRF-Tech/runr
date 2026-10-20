<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * knative_regions.migration_old_state in the tenant schema, as in the central one
 * (database/migrations/2026_10_19_500001): a tenant's own region is moved to another cluster in
 * the tenant's schema, which keeps its pre-move state for a rollback. The copies of platform
 * regions never carry it — the rollback restores their columns centrally, and the region sync
 * passes the restored values on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('knative_regions') && ! Schema::hasColumn('knative_regions', 'migration_old_state')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->json('migration_old_state')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knative_regions') && Schema::hasColumn('knative_regions', 'migration_old_state')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->dropColumn('migration_old_state');
            });
        }
    }
};
