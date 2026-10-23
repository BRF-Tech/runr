<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_21_140001_create_load_tests_table.php: the load
 * tests of the organization's projects (docs/load-test.md), next to the projects themselves —
 * the organization's daily limit counts these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('load_tests') || ! Schema::hasTable('projects')) {
            return;
        }

        Schema::create('load_tests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('deployment_id', 26)->nullable();
            $table->string('region_id', 26)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('method', 8)->default('GET');
            $table->string('host', 253);
            $table->string('path', 1024)->default('/');
            $table->text('body')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->unsignedInteger('users');
            $table->unsignedInteger('duration_seconds');
            $table->string('job_name', 63)->nullable();
            $table->string('namespace', 63)->nullable();
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->string('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_tests');
    }
};
