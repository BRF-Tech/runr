<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * knative_regions.migration_old_state: what a region's columns that a cluster migration sets from
 * the new cluster (the registry address, the dedicated workers and capacity, the install state of
 * its components, …; RegionMigrateService::CLUSTER_STATE) held before the move. A rollback gives
 * them back with the old kubeconfig and external address (migration_old_kubeconfig,
 * migration_old_ip); completing the move forgets them.
 *
 * Every tenant schema gets the same column (Modules/Tenancy/Database/TenantMigrations/0063): a
 * tenant's own region is moved in the tenant's schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            if (! Schema::hasColumn('knative_regions', 'migration_old_state')) {
                $table->json('migration_old_state')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn('migration_old_state');
        });
    }
};
