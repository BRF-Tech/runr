<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_15_000001_add_capacity_admission_columns.php: the
 * region copy carries the FaaS overcommit factors (synced from central for platform regions,
 * written directly for tenant-owned regions; existing regions get the defaults CPU 3 / memory
 * 1.5), projects and model services the RuntimeClass of their last deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('knative_regions', 'faas_cpu_overcommit')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->decimal('faas_cpu_overcommit', 4, 2)->default(3);
                $table->decimal('faas_memory_overcommit', 4, 2)->default(1.5);
            });
        }

        if (Schema::hasTable('projects') && ! Schema::hasColumn('projects', 'deployed_runtime_class')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('deployed_runtime_class', 63)->nullable();
            });
        }

        if (Schema::hasTable('ai_model_services') && ! Schema::hasColumn('ai_model_services', 'runtime_class')) {
            Schema::table('ai_model_services', function (Blueprint $table) {
                $table->string('runtime_class', 63)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_model_services', 'runtime_class')) {
            Schema::table('ai_model_services', function (Blueprint $table) {
                $table->dropColumn('runtime_class');
            });
        }

        if (Schema::hasColumn('projects', 'deployed_runtime_class')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('deployed_runtime_class');
            });
        }

        if (Schema::hasColumn('knative_regions', 'faas_cpu_overcommit')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->dropColumn(['faas_cpu_overcommit', 'faas_memory_overcommit']);
            });
        }
    }
};
