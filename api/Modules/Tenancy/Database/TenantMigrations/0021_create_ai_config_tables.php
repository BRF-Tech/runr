<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aaas_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('filename')->default('agent.py');
            $table->string('handler')->default('handler');
            $table->string('config_mode')->default('blueprint');
            $table->json('blueprint_graph')->nullable();
            $table->json('env_vars')->nullable();
            $table->integer('memory_mb')->default(512);
            $table->string('cpu')->default('500m');
            $table->integer('timeout_seconds')->default(60);
            $table->string('trigger_type')->default('http');
            $table->string('schedule')->nullable();
            $table->string('queue_stream')->nullable();
            $table->char('event_source_project_id', 26)->nullable();
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('event_source_project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::create('agent_flow_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('handler')->default('main.handler');
            $table->string('config_mode')->default('blueprint');
            $table->json('blueprint_graph')->nullable();
            $table->json('env_vars')->nullable();
            $table->integer('memory_mb')->default(512);
            $table->string('cpu')->default('500m');
            $table->integer('timeout_seconds')->default(60);
            $table->string('trigger_type')->default('http');
            $table->string('schedule')->nullable();
            $table->string('queue_stream')->nullable();
            $table->char('event_source_project_id', 26)->nullable();
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('event_source_project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::create('maas_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->json('env_vars')->nullable();
            $table->integer('timeout_seconds')->default(120);
            $table->string('gpu_type')->default('nvidia-a100');
            $table->integer('gpu_count')->default(1);
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maas_configs');
        Schema::dropIfExists('agent_flow_configs');
        Schema::dropIfExists('aaas_configs');
    }
};
