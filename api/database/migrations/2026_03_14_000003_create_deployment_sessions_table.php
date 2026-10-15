<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('region_id')->constrained('knative_regions')->cascadeOnDelete();
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

            $table->index(['project_id', 'started_at']);
            $table->index(['deployment_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_sessions');
    }
};
