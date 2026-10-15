<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Git-native deployment (docs/git-deploy.md).
 *
 * Platform tables (central schema in a multi-tenant install):
 *  - git_providers           GitHub App / GitLab application / Forgejo instances (admin settings)
 *  - git_installations       GitHub App installations linked to an organization
 *  - git_repo_links          project ↔ provider repository (webhook secret, access token)
 *  - git_webhook_deliveries  received webhook deliveries (replay protection, troubleshooting)
 * Project tables (mirrored for tenant schemas by
 * Modules/Tenancy/Database/TenantMigrations/0035_create_git_deploy_tables.php):
 *  - project_git_settings    deploy branch, root directory, auto-deploy, preview settings
 *  - version_git_sources     the commit a version was built from
 *  - git_deploys             deploy history of pushes / pull requests
 *  - preview_environments    preview of a pull/merge request
 *
 * Secrets (private key, client secret, webhook secrets, tokens) are encrypted with the
 * application key (Eloquent "encrypted" casts) and never returned by the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 16);                 // github | gitlab | forgejo
            $table->string('name', 100);
            $table->string('base_url')->nullable();     // web URL (https://github.com, https://gitlab.com, https://code.example.com)
            $table->string('api_url')->nullable();      // empty: derived from base_url
            $table->string('app_id', 64)->nullable();   // GitHub App id
            $table->string('app_slug', 100)->nullable(); // GitHub App slug (installation URL)
            $table->string('client_id')->nullable();    // GitHub App / GitLab OAuth application
            $table->text('client_secret')->nullable();  // encrypted
            $table->text('private_key')->nullable();    // encrypted (GitHub App PEM)
            $table->text('webhook_secret')->nullable(); // encrypted (GitHub App webhook secret)
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('type');
        });

        Schema::create('git_installations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('git_provider_id', 26)->index();
            $table->string('installation_id', 64);
            $table->string('account_login')->nullable();
            $table->string('account_type', 32)->nullable();
            $table->string('tenant_id')->nullable()->index();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['git_provider_id', 'installation_id', 'tenant_id']);
        });

        Schema::create('git_repo_links', function (Blueprint $table) {
            $table->ulid('id')->primary();              // also the webhook path segment (GitLab / Forgejo)
            $table->string('tenant_id')->nullable()->index();
            $table->string('project_id', 26)->index();
            $table->string('git_provider_id', 26)->index();
            $table->string('provider_type', 16);
            $table->string('repo_id', 64);
            $table->string('repo_full_name');
            $table->string('repo_url')->nullable();
            $table->string('default_branch')->nullable();
            $table->string('installation_id', 64)->nullable(); // GitHub
            $table->string('auth_mode', 16)->default('app');   // app | pat | oauth
            $table->text('access_token')->nullable();          // encrypted
            $table->text('refresh_token')->nullable();         // encrypted
            $table->timestamp('token_expires_at')->nullable();
            $table->text('webhook_secret')->nullable();        // encrypted (GitLab token / Forgejo HMAC key)
            $table->string('external_hook_id', 64)->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id']);
            $table->index(['provider_type', 'repo_id']);
        });

        Schema::create('git_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('provider_type', 16);
            $table->string('delivery_id', 100);
            $table->string('event', 64)->nullable();
            $table->string('git_provider_id', 26)->nullable();
            $table->string('git_repo_link_id', 26)->nullable();
            $table->string('status', 16);               // accepted | ignored | rejected
            $table->string('message')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['provider_type', 'delivery_id']);
            $table->index('created_at');
        });

        $this->createProjectTables();
    }

    private function createProjectTables(): void
    {
        Schema::create('project_git_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('deploy_branch')->default('main');
            $table->string('root_dir')->nullable();
            $table->boolean('auto_deploy')->default(true);
            $table->boolean('allow_force_push')->default(false);
            $table->boolean('previews_enabled')->default(false);
            $table->unsignedInteger('preview_limit')->default(3);
            $table->unsignedInteger('preview_ttl_hours')->default(72);
            $table->text('preview_env')->nullable();          // env overrides, encrypted (Encrypted:array cast)
            $table->boolean('fork_previews')->default(false);
            $table->boolean('fork_secrets')->default(false);  // expose secret env vars to fork previews
            $table->json('runr_yaml')->nullable();            // last applied runr.yaml (normalized)
            $table->timestamps();
        });

        Schema::create('version_git_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('version_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);                   // runr | github | gitlab | forgejo
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
        });

        Schema::create('git_deploys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('version_id', 26)->nullable()->index();
            $table->string('deployment_id', 26)->nullable()->index();
            $table->string('preview_environment_id', 26)->nullable()->index();
            $table->string('kind', 16);                       // production | preview
            $table->string('trigger', 16);                    // push | pull_request | redeploy
            $table->string('provider', 16);                   // runr | github | gitlab | forgejo
            $table->string('sha', 64);
            $table->string('branch')->nullable();
            $table->string('author_name')->nullable();
            $table->text('message')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('status', 16)->default('queued');
            $table->text('status_message')->nullable();
            $table->json('details')->nullable();                // warnings, skipped files, applied runr.yaml settings
            $table->string('provider_deployment_id', 64)->nullable(); // GitHub Deployments API
            $table->string('reported_state', 16)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        Schema::create('preview_environments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');                // pull / merge request number
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_environments');
        Schema::dropIfExists('git_deploys');
        Schema::dropIfExists('version_git_sources');
        Schema::dropIfExists('project_git_settings');
        Schema::dropIfExists('git_webhook_deliveries');
        Schema::dropIfExists('git_repo_links');
        Schema::dropIfExists('git_installations');
        Schema::dropIfExists('git_providers');
    }
};
