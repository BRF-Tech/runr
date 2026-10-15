<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create tenant-scoped metric_snapshots table.
     * Mirrors central schema to ensure tenant data isolation.
     */
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->char('deployment_id', 26)->nullable();
            $table->char('region_id', 26);
            $table->timestamp('recorded_at')->index();
            $table->enum('granularity', ['1m', '5m', '1h', '1d']);
            $table->decimal('cpu_cores', 10, 6)->nullable();
            $table->decimal('memory_mb', 10, 2)->nullable();
            $table->decimal('rps', 10, 3)->nullable();
            $table->decimal('concurrency', 10, 3)->nullable();
            $table->integer('pod_count')->nullable();
            $table->integer('cold_starts')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('deployment_id')->references('id')->on('deployments')->nullOnDelete();
            $table->foreign('region_id')->references('id')->on('knative_regions')->cascadeOnDelete();

            $table->index(['project_id', 'recorded_at']);
            $table->index(['region_id', 'recorded_at']);
            $table->index(['deployment_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};
