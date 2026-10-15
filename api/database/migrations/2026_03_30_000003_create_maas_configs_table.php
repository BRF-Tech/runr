<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maas_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->json('env_vars')->nullable();
            $table->integer('timeout_seconds')->default(120);
            $table->string('gpu_type')->default('nvidia-a100');
            $table->integer('gpu_count')->default(1);
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maas_configs');
    }
};
