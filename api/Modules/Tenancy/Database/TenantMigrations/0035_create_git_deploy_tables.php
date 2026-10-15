<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of the project tables of database/migrations/2026_10_10_200001_create_git_deploy_tables.php
 * (git settings, version sources, deploy history, preview environments). The platform tables
 * (git_providers, git_installations, git_repo_links, git_webhook_deliveries) stay central.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_git_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26)->unique();
            $table->string('deploy_branch')->default('main');
            $table->string('root_dir')->nullable();
            $table->boolean('auto_deploy')->default(true);
            $table->boolean('allow_force_push')->default(false);
            $table->boolean('previews_enabled')->default(false);
            $table->unsignedInteger('preview_limit')->default(3);
            $table->unsignedInteger('preview_ttl_hours')->default(72);
            $table->text('preview_env')->nullable();          // encrypted (Encrypted:array cast)
            $table->boolean('fork_previews')->default(false);
            $table->boolean('fork_secrets')->default(false);
            $table->json('runr_yaml')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('version_git_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('version_id', 26)->unique();
            $table->char('project_id', 26);
            $table->string('provider', 16);
            $table->string('sha', 64);
            $table->string('ref')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->text('message')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pushed_by')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'sha']);
            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('git_deploys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('version_id', 26)->nullable()->index();
            $table->string('deployment_id', 26)->nullable()->index();
            $table->string('preview_environment_id', 26)->nullable()->index();
            $table->string('kind', 16);
            $table->string('trigger', 16);
            $table->string('provider', 16);
            $table->string('sha', 64);
            $table->string('branch')->nullable();
            $table->string('author_name')->nullable();
            $table->text('message')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('status', 16)->default('queued');
            $table->text('status_message')->nullable();
            $table->json('details')->nullable();                // warnings, skipped files, applied runr.yaml settings
            $table->string('provider_deployment_id', 64)->nullable();
            $table->string('reported_state', 16)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('preview_environments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->unsignedInteger('number');
            $table->string('provider', 16);
            $table->string('git_repo_link_id', 26)->nullable();
            $table->string('title')->nullable();
            $table->string('branch')->nullable();
            $table->string('base_branch')->nullable();
            $table->string('head_sha', 64)->nullable();
            $table->string('author')->nullable();
            $table->string('pr_url')->nullable();
            $table->boolean('is_fork')->default(false);
            $table->string('service_name', 63);
            $table->string('url')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('status_message')->nullable();
            $table->string('version_id', 26)->nullable();
            $table->string('comment_id', 64)->nullable();
            $table->string('provider_deployment_id', 64)->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['status', 'expires_at']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_environments');
        Schema::dropIfExists('git_deploys');
        Schema::dropIfExists('version_git_sources');
        Schema::dropIfExists('project_git_settings');
    }
};
