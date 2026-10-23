<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rightsizing (docs/monitoring.md#rightsizing, App\Services\Rightsizing):
 *
 *  - resource_peaks: one row per project and day — the highest and the 95th percentile
 *    working-set memory and CPU of one of its pods, read from the region's Prometheus after the
 *    day (it keeps 15 days; metric_snapshots keep sums over all pods and only averages after a
 *    week), with the size the project ran with, OOM kills and the share of throttled CPU periods
 *  - rightsizing_recommendations: the current recommendation of a project (at most one) with its
 *    evidence, the estimated monthly difference and what the members decided (applied, dismissed,
 *    snoozed)
 *
 * Every tenant schema gets the same tables (Modules/Tenancy/Database/TenantMigrations/0073).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resource_peaks')) {
            Schema::create('resource_peaks', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('project_id', 26);
                $table->string('region_id', 26)->nullable();
                $table->date('day');
                $table->string('trigger_type', 16)->default('http');
                // the size of the deployment that ran (per pod)
                $table->unsignedInteger('cpu_m')->default(0);
                $table->unsignedInteger('memory_mb')->default(0);
                // null: no reading of that kind (no pod ran, metric not collected)
                $table->decimal('memory_max_mb', 12, 2)->nullable();
                $table->decimal('memory_p95_mb', 12, 2)->nullable();
                $table->unsignedInteger('cpu_max_m')->nullable();
                $table->unsignedInteger('cpu_p95_m')->nullable();
                $table->decimal('throttled_ratio', 6, 4)->nullable();
                $table->unsignedInteger('oom_kills')->default(0);
                // memory samples and distinct pods behind the day
                $table->unsignedInteger('samples')->default(0);
                $table->unsignedInteger('pods')->default(0);
                $table->timestamps();

                $table->unique(['project_id', 'day']);
                $table->index('day');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('rightsizing_recommendations')) {
            Schema::create('rightsizing_recommendations', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('project_id', 26)->unique();
                $table->string('region_id', 26)->nullable();
                // open | snoozed | dismissed | applied
                $table->string('status', 16)->default('open');
                // down | up | mixed
                $table->string('direction', 8);
                $table->unsignedInteger('current_cpu_m');
                $table->unsignedInteger('current_memory_mb');
                $table->unsignedInteger('recommended_cpu_m');
                $table->unsignedInteger('recommended_memory_mb');
                $table->json('reasons');
                $table->json('evidence');
                // {basis, monthly, currency, …} of the Billing module; null without billing
                $table->json('savings')->nullable();
                // positive: saved per month; negative: costs more (sorting the organization's list)
                $table->decimal('monthly_savings', 14, 4)->nullable();
                $table->string('currency', 3)->nullable();
                // {code, message} when applying would be refused (region capacity, fixed allocation)
                $table->json('blocked')->nullable();
                $table->timestamp('computed_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->string('decided_by', 64)->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('applied_version_id', 26)->nullable();
                $table->timestamps();

                $table->index('status');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rightsizing_recommendations');
        Schema::dropIfExists('resource_peaks');
    }
};
