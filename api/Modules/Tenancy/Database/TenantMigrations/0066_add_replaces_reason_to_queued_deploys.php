<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * queued_deploys.replaces_reason in the tenant schema, as in the central one
 * (database/migrations/2026_10_20_200001): an organization's project on a moved region is deployed
 * in the organization's schema, and a deploy of an older version there takes the place of the
 * move's deploy of the project the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queued_deploys') && ! Schema::hasColumn('queued_deploys', 'replaces_reason')) {
            Schema::table('queued_deploys', function (Blueprint $table) {
                $table->string('replaces_reason', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('queued_deploys') && Schema::hasColumn('queued_deploys', 'replaces_reason')) {
            Schema::table('queued_deploys', function (Blueprint $table) {
                $table->dropColumn('replaces_reason');
            });
        }
    }
};
