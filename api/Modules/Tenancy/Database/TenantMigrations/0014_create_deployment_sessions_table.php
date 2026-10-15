<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create tenant-scoped deployment_sessions table.
     * Mirrors central schema to ensure tenant data isolation.
     */
    public function up(): void
    {
        Schema::create('deployment_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->char('deployment_id', 26);
            $table->char('region_id', 26);
            $table->enum('entry_point', ['http', 'schedule', 'queue']);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->bigInteger('duration_ms')->nullable();
            $table->bigInteger('cpu_used_ms')->nullable();
            $table->bigInteger('memory_used_mb_ms')->nullable();
            $table->integer('pod_count')->default(1);
            $table->boolean('cold_start')->default(false);
            $table->integer('cold_start_ms')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('deployment_id')->references('id')->on('deployments')->cascadeOnDelete();
            $table->foreign('region_id')->references('id')->on('knative_regions')->cascadeOnDelete();

            $table->index(['project_id', 'started_at']);
            $table->index(['deployment_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_sessions');
    }
};
