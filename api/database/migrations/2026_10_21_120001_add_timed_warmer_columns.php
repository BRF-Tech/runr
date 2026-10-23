<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The timed warmer (docs/scaling.md#timed-warmer):
 *  - xaas_configs / faas_configs / container_configs.scale_schedule: the rows of cron and container
 *    count (App\Support\Projects\ScaleSchedule), null while the warmer is off
 *  - projects.warm_scale_applied / warm_scale_applied_at: the min-scale the warmer last applied to
 *    the live revision's PodAutoscaler and when (App\Services\Projects\WarmScaleService), for the
 *    console
 *
 * Every tenant schema gets the same columns (Modules/Tenancy/Database/TenantMigrations/0069): the
 * organizations' projects live there.
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
