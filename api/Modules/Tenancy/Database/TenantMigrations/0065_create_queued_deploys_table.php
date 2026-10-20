<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_20_100002_create_queued_deploys_table.php: deploys
 * that wait for their version's build (App\Services\Projects\QueuedDeploys), next to the
 * organization's projects, versions and deployments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queued_deploys') || ! Schema::hasTable('projects') || ! Schema::hasTable('versions')) {
            return;
        }

        Schema::create('queued_deploys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->char('version_id', 26);
            $table->string('status', 16)->default('waiting');
            $table->string('reason', 32)->nullable();
            $table->string('deployed_by');
            $table->string('initiator_id', 64)->nullable();
            $table->json('deploy_config')->nullable();
            $table->string('replaces_deployment_id', 26)->nullable();
            $table->string('previous_build_image')->nullable();
            $table->string('deployment_id', 26)->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['version_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queued_deploys');
    }
};
