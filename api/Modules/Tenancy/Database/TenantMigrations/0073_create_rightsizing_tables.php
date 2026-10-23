<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_21_160001_create_rightsizing_tables.php: the daily
 * peaks of the organization's projects and their rightsizing recommendations
 * (App\Services\Rightsizing), next to the projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }

        if (! Schema::hasTable('resource_peaks')) {
            Schema::create('resource_peaks', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('project_id', 26);
                $table->string('region_id', 26)->nullable();
                $table->date('day');
                $table->string('trigger_type', 16)->default('http');
                $table->unsignedInteger('cpu_m')->default(0);
                $table->unsignedInteger('memory_mb')->default(0);
                $table->decimal('memory_max_mb', 12, 2)->nullable();
                $table->decimal('memory_p95_mb', 12, 2)->nullable();
                $table->unsignedInteger('cpu_max_m')->nullable();
                $table->unsignedInteger('cpu_p95_m')->nullable();
                $table->decimal('throttled_ratio', 6, 4)->nullable();
                $table->unsignedInteger('oom_kills')->default(0);
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
                $table->string('status', 16)->default('open');
                $table->string('direction', 8);
                $table->unsignedInteger('current_cpu_m');
                $table->unsignedInteger('current_memory_mb');
                $table->unsignedInteger('recommended_cpu_m');
                $table->unsignedInteger('recommended_memory_mb');
                $table->json('reasons');
                $table->json('evidence');
                $table->json('savings')->nullable();
                $table->decimal('monthly_savings', 14, 4)->nullable();
                $table->string('currency', 3)->nullable();
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
