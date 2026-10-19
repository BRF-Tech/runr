<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metering by what really ran (docs/monitoring.md#metering): a deployment records when it stopped
 * running (ended_at — set when it leaves pending / running / active) and the size it was applied
 * with (sizing: CPU, memory, scale settings, GPUs and region of the project config with the
 * deploy's overrides). An always-on workload is metered for the time it was active on the day with
 * that size, not with today's config for 24 hours. Deployments that are no longer live get their
 * last change (updated_at) as ended_at.
 *
 * projects.stop_level: who stopped a project — "platform" (a platform administrator) or
 * "organization" (an administrator of the organization). Resuming needs the same level or a
 * higher one (docs/billing.md#project-stop-and-resume).
 *
 * Every tenant schema gets the same columns (Modules/Tenancy/Database/TenantMigrations/0062).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            if (! Schema::hasColumn('deployments', 'ended_at')) {
                $table->timestamp('ended_at')->nullable();
            }
            if (! Schema::hasColumn('deployments', 'sizing')) {
                $table->json('sizing')->nullable();
            }
        });

        // Deployments that stopped running before the upgrade: their last change is when (as close
        // as is known) — a later change between states that are not live keeps it
        DB::table('deployments')->whereNull('ended_at')->whereNotIn('status', ['pending', 'running', 'active'])
            ->update(['ended_at' => DB::raw('updated_at')]);

        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'stop_level')) {
                $table->string('stop_level', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['ended_at', 'sizing']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('stop_level');
        });
    }
};
