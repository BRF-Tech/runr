<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knative_regions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('api_url', 255);
            $table->unsignedInteger('total_cpu_m')->default(0);
            $table->unsignedInteger('total_memory_mb')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knative_regions');
    }
};
