<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->integer('number')->unsigned();
            $table->string('commit_id', 40)->nullable();       // null → CaaS config snapshot version
            $table->string('commit_message')->nullable();
            $table->string('created_by');
            $table->json('changed_files')->nullable();
            $table->json('config_snapshot')->nullable();       // CaaS: config snapshot
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'number']);
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->ulid('version_id');
            // pending → running → active   |  shutting_down (previous) | failed
            $table->string('status')->default('pending');
            $table->string('k8s_job_name')->nullable();
            $table->string('deployed_by')->nullable();         // username
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('versions');
    }
};
