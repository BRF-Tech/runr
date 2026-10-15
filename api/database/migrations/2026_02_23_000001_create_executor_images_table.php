<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executor_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('runtime');       // python, nodejs, go, php
            $table->string('version');       // 3.12, 22, 1.26, 8.4
            $table->string('docker_image');  // runrsh/python:3.12
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['runtime', 'version']);
            $table->index('runtime');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executor_images');
    }
};
