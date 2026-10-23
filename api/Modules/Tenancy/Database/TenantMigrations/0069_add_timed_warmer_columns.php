<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The timed warmer in the tenant schema, as in the central one
 * (database/migrations/2026_10_21_120001): the organization's projects live here.
 *  - xaas_configs / faas_configs / container_configs.scale_schedule: rows of cron and container
 *    count, null while the warmer is off
 *  - projects.warm_scale_applied / warm_scale_applied_at: what the warmer last applied and when
 */
return new class extends Migration
{
    private const CONFIGS = ['xaas_configs', 'faas_configs', 'container_configs'];

    public function up(): void
    {
        foreach (self::CONFIGS as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'scale_schedule')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->json('scale_schedule')->nullable();
                });
            }
        }

        if (Schema::hasTable('projects') && ! Schema::hasColumn('projects', 'warm_scale_applied')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->unsignedInteger('warm_scale_applied')->nullable();
                $table->timestamp('warm_scale_applied_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::CONFIGS as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'scale_schedule')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('scale_schedule');
                });
            }
        }

        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'warm_scale_applied')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn(['warm_scale_applied', 'warm_scale_applied_at']);
            });
        }
    }
};
