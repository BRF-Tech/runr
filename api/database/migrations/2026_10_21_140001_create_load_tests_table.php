<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * load_tests: the load tests of a project (docs/load-test.md) — a k6 Job in the project's region
 * against the project's own URL, its parameters, status and results (latency percentiles, error
 * rate, requests per second, the pods the project scaled to and its cold starts in the window).
 *
 * Every tenant schema gets the same table (Modules/Tenancy/Database/TenantMigrations/0071): the
 * organizations' projects live there, and the daily limit counts an organization's own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('load_tests')) {
            return;
        }

        Schema::create('load_tests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            // the deployment that served the test and the region it ran in
            $table->string('deployment_id', 26)->nullable();
            $table->string('region_id', 26)->nullable();
            // pending | running | completed | failed | cancelled
            $table->string('status', 16)->default('pending');
            $table->string('method', 8)->default('GET');
            // the project's function host or one of its verified domains, and the path on it
            $table->string('host', 253);
            $table->string('path', 1024)->default('/');
            $table->text('body')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->unsignedInteger('users');
            $table->unsignedInteger('duration_seconds');
            // the k6 Job in the region
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
