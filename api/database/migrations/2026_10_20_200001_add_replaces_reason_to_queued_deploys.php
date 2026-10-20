<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * queued_deploys.replaces_reason: the reason of the waiting deploy a queued deploy took the place
 * of, when that one ends the deployment it replaces on a failure — a region migration's
 * (App\Services\Projects\QueuedDeploys). A deploy of an older version during a move replaces the
 * move's deploy of the project; the project's live deployment still runs on the cluster the
 * region left, and when that deploy fails or is refused, the deployment ends as one the move
 * refused, as it would have with the move's own deploy.
 *
 * Every tenant schema gets the same column (Modules/Tenancy/Database/TenantMigrations/0066): the
 * organizations' projects live there.
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
