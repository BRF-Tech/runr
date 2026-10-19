<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metering in the core (App\Services\Metering\UsageAggregator, docs/monitoring.md): the daily
 * usage of the projects and model services of the central schema — every project of a single-
 * tenant install, the platform-level projects of a multi-tenant one — in usage_records, as each
 * tenant schema already has them (TenantMigrations 0009 + 0045). With tenancy on the central table
 * also holds a copy of every tenant's usage in platform regions (tenant_id set): what the platform
 * bills a tenant for (docs/billing.md) is readable centrally.
 *
 * created_by: the creator of a model service (usage that belongs to no project is billed to its
 * creator). binding_usage_records get tenant_id (copies of tenant usage in platform regions) and
 * created_by; sandbox_usage_records get created_by (the user who created the sandboxes).
 *
 * Quantities only: raw_cost / final_cost stay for the rows of earlier releases; prices are applied
 * when bills are calculated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('usage_records')) {
            Schema::create('usage_records', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('tenant_id')->nullable()->index();
                $table->ulid('project_id');
                $table->ulid('deployment_id');
                $table->ulid('region_id');
                $table->date('record_date')->index();
                $table->string('scale_mode', 16);
                $table->string('entry_point', 16);
                $table->string('source', 16)->default('project');   // project | model
                $table->string('created_by')->nullable();
                $table->integer('session_count')->default(0);
                $table->bigInteger('total_duration_ms')->default(0);
                $table->bigInteger('total_cpu_mc_ms')->default(0);
                $table->bigInteger('total_memory_mib_ms')->default(0);
                $table->integer('cold_start_count')->default(0);
                $table->integer('reserved_cpu_m')->default(0);
                $table->integer('reserved_memory_mb')->default(0);
                $table->integer('min_scale')->default(0);
                $table->unsignedInteger('gpu_count')->default(0);
                $table->unsignedBigInteger('total_gpu_unit_ms')->default(0);
                $table->decimal('raw_cost', 12, 6)->default(0);
                $table->decimal('multiplier', 5, 2)->default(1.0);
                $table->decimal('final_cost', 12, 6)->default(0);
                $table->string('currency', 3)->default('USD');
                $table->timestamps();

                $table->index(['project_id', 'record_date']);
                $table->index(['region_id', 'record_date']);
            });
        }

        Schema::table('binding_usage_records', function (Blueprint $table) {
            if (! Schema::hasColumn('binding_usage_records', 'tenant_id')) {
                $table->string('tenant_id')->nullable()->index();
            }
            if (! Schema::hasColumn('binding_usage_records', 'created_by')) {
                $table->string('created_by')->nullable();
            }
        });

        Schema::table('sandbox_usage_records', function (Blueprint $table) {
            if (! Schema::hasColumn('sandbox_usage_records', 'created_by')) {
                $table->string('created_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sandbox_usage_records', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
        Schema::table('binding_usage_records', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn(['tenant_id', 'created_by']);
        });
        Schema::dropIfExists('usage_records');
    }
};
