<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPU support (docs/ai.md):
 *  - knative_regions: install status + log of the NVIDIA GPU support (device plugin / GPU
 *    Operator), its settings (time-slicing, RuntimeClass, gVisor nvproxy) and the region's model
 *    volume settings (PVC of the model cache and offline snapshots). gpu_mode / gpu_available /
 *    gpu_allocated exist since 2026_03_30_000005.
 *  - xaas / faas / container configs: the GPU request of a project {count, type, memory_min}.
 */
return new class extends Migration
{
    private const CONFIG_TABLES = ['xaas_configs', 'faas_configs', 'container_configs'];

    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        foreach (self::CONFIG_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('gpu');
            });
        }

        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['gpu_status', 'gpu_status_message', 'gpu_settings', 'model_storage']);
        });
    }
};
