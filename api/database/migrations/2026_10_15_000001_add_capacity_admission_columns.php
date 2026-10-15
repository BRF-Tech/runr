<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Region capacity admission with FaaS overcommit (App\Services\Helpers\RegionCapacity,
 * docs/architecture.md → Region capacity and admission):
 *  - knative_regions.faas_cpu_overcommit / faas_memory_overcommit: the factor scale-to-zero
 *    workloads may overcommit the region capacity by — every existing region gets the defaults
 *    (CPU 3, memory 1.5)
 *  - projects.deployed_runtime_class, ai_model_services.runtime_class: the RuntimeClass of the
 *    last deploy ("runc" for none), whose Kata pod overhead the region capacity counts; null until
 *    the next deploy (the region's mapping counts until then)
 * Tenant schemas: TenantMigrations/0052.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->decimal('faas_cpu_overcommit', 4, 2)->default(3);
            $table->decimal('faas_memory_overcommit', 4, 2)->default(1.5);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('deployed_runtime_class', 63)->nullable();
        });

        if (Schema::hasTable('ai_model_services')) {
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

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('deployed_runtime_class');
        });

        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['faas_cpu_overcommit', 'faas_memory_overcommit']);
        });
    }
};
