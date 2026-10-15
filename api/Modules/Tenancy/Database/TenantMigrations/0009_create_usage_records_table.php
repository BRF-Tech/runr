<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->ulid('deployment_id');
            $table->ulid('region_id');
            $table->date('record_date')->index();
            $table->enum('scale_mode', ['scale_to_zero', 'custom_scale']);
            $table->enum('entry_point', ['http', 'schedule', 'queue']);
            $table->integer('session_count')->default(0);
            $table->bigInteger('total_duration_ms')->default(0);
            $table->bigInteger('total_cpu_mc_ms')->default(0);
            $table->bigInteger('total_memory_mib_ms')->default(0);
            $table->integer('cold_start_count')->default(0);
            $table->integer('reserved_cpu_m')->default(0);
            $table->integer('reserved_memory_mb')->default(0);
            $table->integer('min_scale')->default(0);
            $table->decimal('raw_cost', 12, 6)->default(0);
            $table->decimal('multiplier', 5, 2)->default(1.0);
            $table->decimal('final_cost', 12, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->index(['project_id', 'record_date']);
            $table->index(['region_id', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
