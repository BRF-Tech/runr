<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * deployments.ended_at / deployments.sizing (metering by what really ran) and projects.stop_level
 * (who stopped a project) in the tenant schema — the central tables get the same columns
 * (database/migrations/2026_10_19_400001).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deployments')) {
            Schema::table('deployments', function (Blueprint $table) {
                if (! Schema::hasColumn('deployments', 'ended_at')) {
                    $table->timestamp('ended_at')->nullable();
                }
                if (! Schema::hasColumn('deployments', 'sizing')) {
                    $table->json('sizing')->nullable();
                }
            });

            // Deployments that stopped running before the upgrade: their last change is when
            DB::table('deployments')->whereNull('ended_at')->whereNotIn('status', ['pending', 'running', 'active'])
                ->update(['ended_at' => DB::raw('updated_at')]);
        }

        if (Schema::hasTable('projects') && ! Schema::hasColumn('projects', 'stop_level')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('stop_level', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('deployments') && Schema::hasColumn('deployments', 'ended_at')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->dropColumn(['ended_at', 'sizing']);
            });
        }
        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'stop_level')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('stop_level');
            });
        }
    }
};
