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
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
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
            $table->foreignUlid('event_source_project_id')->nullable()
                ->constrained('projects')->nullOnDelete();
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aaas_configs');
    }
};
