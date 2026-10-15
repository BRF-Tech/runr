<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of the GPU columns (database/migrations/2026_03_30_000005_add_gpu_fields_to_knative_regions.php
 * and 2026_10_10_300001_add_gpu_support_columns.php): the region copy carries the GPU inventory,
 * install status and model volume settings (synced from central for platform regions, written
 * directly for tenant-owned regions), project configs their GPU request, and usage records the
 * metered GPU time.
 */
return new class extends Migration
{
    private const CONFIG_TABLES = ['xaas_configs', 'faas_configs', 'container_configs'];

    public function up(): void
    {
        $hasGpuMode = Schema::hasColumn('knative_regions', 'gpu_mode');

        Schema::table('knative_regions', function (Blueprint $table) use ($hasGpuMode) {
            if (! $hasGpuMode) {
                $table->string('gpu_mode')->default('none');
                $table->json('gpu_available')->nullable();
                $table->json('gpu_allocated')->nullable();
            }
            $table->string('gpu_status', 20)->default('not_installed');
            $table->text('gpu_status_message')->nullable();
            $table->json('gpu_settings')->nullable();
            $table->json('model_storage')->nullable();
        });

        foreach (self::CONFIG_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('gpu')->nullable();
            });
        }

        Schema::table('usage_records', function (Blueprint $table) {
            $table->string('source', 16)->default('project');   // project | model
            $table->unsignedInteger('gpu_count')->default(0);
            $table->unsignedBigInteger('total_gpu_unit_ms')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropColumn(['source', 'gpu_count', 'total_gpu_unit_ms']);
        });

        foreach (self::CONFIG_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('gpu');
            });
        }

        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['gpu_status', 'gpu_status_message', 'gpu_settings', 'model_storage', 'gpu_mode', 'gpu_available', 'gpu_allocated']);
        });
    }
};
