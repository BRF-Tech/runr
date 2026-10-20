<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * knative_regions.sandbox_kata_devmapper_pool_gb: the size in GiB of the devmapper thin-pool that
 * Kata's Firecracker hypervisor runs on, as it was set up on the region's nodes
 * (SandboxInstallService::installKata). A Kata install that adds hypervisors keeps it, a region
 * migration sets the pool up with it on the new cluster, and a migration rollback gives it back
 * with the region's other state (RegionMigrateService::CLUSTER_STATE). Null: no Firecracker pool
 * set up yet, or one set up before this column existed (the default size is used then).
 *
 * Every tenant schema gets the same column (Modules/Tenancy/Database/TenantMigrations/0064): a
 * tenant's own region installs Kata in the tenant's schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            if (! Schema::hasColumn('knative_regions', 'sandbox_kata_devmapper_pool_gb')) {
                $table->unsignedSmallInteger('sandbox_kata_devmapper_pool_gb')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            if (Schema::hasColumn('knative_regions', 'sandbox_kata_devmapper_pool_gb')) {
                $table->dropColumn('sandbox_kata_devmapper_pool_gb');
            }
        });
    }
};
