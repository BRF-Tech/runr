<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('service_type');   // xaas | faas | caas
            $table->string('runtime')->nullable();
            $table->text('description')->nullable();
            $table->string('git_path')->nullable();
            $table->timestamps();
        });

        Schema::create('container_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->string('image');
            $table->json('command')->nullable();
            $table->json('args')->nullable();
            $table->json('env_vars')->nullable();
            $table->json('ports')->nullable();
            $table->integer('memory_mb')->default(128);
            $table->string('cpu')->default('500m');
            $table->integer('timeout_seconds')->default(30);
            $table->string('image_pull_policy')->default('IfNotPresent');
            $table->string('image_pull_secret')->nullable();
            $table->string('trigger_type')->default('http');   // http | schedule
            $table->string('schedule')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('xaas_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->string('filename');
            $table->json('env_vars')->nullable();
            $table->integer('memory_mb')->default(128);
            $table->integer('timeout_seconds')->default(30);
            $table->string('trigger_type')->default('http');   // http | schedule
            $table->string('schedule')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('faas_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->string('handler');
            $table->json('env_vars')->nullable();
            $table->integer('memory_mb')->default(128);
            $table->integer('timeout_seconds')->default(30);
            $table->string('trigger_type')->default('http');   // http | schedule
            $table->string('schedule')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faas_configs');
        Schema::dropIfExists('xaas_configs');
        Schema::dropIfExists('container_configs');
        Schema::dropIfExists('projects');
    }
};
