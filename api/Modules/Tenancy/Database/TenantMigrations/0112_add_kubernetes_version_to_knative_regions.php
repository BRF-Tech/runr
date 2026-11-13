<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * knative_regions.kubernetes_version in the tenant schema, as in the central one
 * (database/migrations/2026_11_12_100001): a tenant's own region records its cluster's version
 * there, and the copy of a platform region receives the platform's (the tenant's terminals and
 * sandbox commands on it pick kubectl's minor from the copy). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('knative_regions') && ! Schema::hasColumn('knative_regions', 'kubernetes_version')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->string('kubernetes_version', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knative_regions') && Schema::hasColumn('knative_regions', 'kubernetes_version')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->dropColumn('kubernetes_version');
            });
        }
    }
};
