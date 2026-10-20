<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * knative_regions.sandbox_kata_devmapper_pool_gb in the tenant schema, as in the central one
 * (database/migrations/2026_10_20_100001): a tenant's own region installs Kata Firecracker, moves
 * to another cluster and rolls back in the tenant's schema. The copies of platform regions do not
 * carry it (the platform installs Kata on its regions centrally).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('knative_regions') && ! Schema::hasColumn('knative_regions', 'sandbox_kata_devmapper_pool_gb')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->unsignedSmallInteger('sandbox_kata_devmapper_pool_gb')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knative_regions') && Schema::hasColumn('knative_regions', 'sandbox_kata_devmapper_pool_gb')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->dropColumn('sandbox_kata_devmapper_pool_gb');
            });
        }
    }
};
