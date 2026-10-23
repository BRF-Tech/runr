<?php

namespace App\Services\Environments;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables and columns of project environments (docs/environments.md), defined once for the central
 * migration (database/migrations/2026_10_21_220001_create_project_environments.php) and the tenant
 * copy (Modules/Tenancy/Database/TenantMigrations/0080_create_project_environments.php):
 *
 *  - project_environments   dev / staging / … of a project, the primary (production) row too
 *  - environment_secrets    secret env vars of one environment (over the project's own):
 *                           metadata only, the values live in the environment's region
 *  - environment_promotions a version moved from one environment to the next, with its approval
 *  - deployments            environment_id (null: the primary environment), config_snapshot (the
 *                           effective config the deployment was applied with, encrypted) and
 *                           config_source (current | restored)
 *  - queued_deploys         config_snapshot (a rollback that waits for its version's build)
 *  - binding_attachments, project_domains, git_deploys, usage_records:
 *                           environment_id (null: the primary environment)
 *
 * The added columns carry no foreign key (SQLite rebuilds a table for one): an environment's rows
 * go with it in EnvironmentManager::delete().
 */
final class EnvironmentSchema
{
    public const TABLES = ['environment_promotions', 'environment_secrets', 'project_environments'];

    /** Tables that get environment_id. */
    public const ENVIRONMENT_COLUMNS = ['deployments', 'binding_attachments', 'project_domains', 'git_deploys', 'usage_records'];

    public static function up(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }

        if (! Schema::hasTable('project_environments')) {
            Schema::create('project_environments', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
                // DNS label, unique per project (dev, staging, qa-eu, …); "production" for the primary row
                $table->string('name', 20);
                // The project's own workload (Knative Service <slug>): one per project, created on first use
                $table->boolean('is_primary')->default(false);
                // Base name of the environment's workloads: <slug> for the primary, <slug>-<name> otherwise
                $table->string('workload_name', 63);
                // null: the project's region
                $table->string('knative_region_id', 26)->nullable()->index();
                // Pipeline order (promotion goes to the next one); the primary is last by default
                $table->unsignedInteger('position')->default(0);
                // Promotions into it wait for an approver (environments.approve)
                $table->boolean('requires_approval')->default(false);
                // A push to this branch deploys here (the primary uses project_git_settings.deploy_branch)
                $table->string('git_branch', 255)->nullable();
                $table->boolean('auto_deploy')->default(true);
                // Whether the project's secret env vars reach this environment under its own
                $table->boolean('inherit_secrets')->default(true);
                // Config overrides over the project's config (env vars included: encrypted)
                $table->text('overrides')->nullable();
                $table->string('deployed_runtime_class', 64)->nullable();
                // Version that ran when the project was stopped (redeployed on resume)
                $table->string('stopped_version_id', 26)->nullable();
                $table->string('created_by', 64)->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'name']);
                $table->index(['project_id', 'position']);
            });
        }

        if (! Schema::hasTable('environment_secrets')) {
            Schema::create('environment_secrets', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignUlid('environment_id')->constrained('project_environments')->cascadeOnDelete();
                $table->string('name');
                // Pass-through, like project_secrets: the value lives in the environment's region
                // (the Secret <workload>-secret-env), never here. Where it is, how often it was
                // written, a keyed fingerprint of it (SecretFingerprint, never a plain hash)
                $table->string('source', 16)->default('region');
                $table->unsignedInteger('version')->default(1);
                $table->string('fingerprint', 80)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['environment_id', 'name']);
            });
        }

        if (! Schema::hasTable('environment_promotions')) {
            Schema::create('environment_promotions', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
                // null: the primary environment
                $table->string('source_environment_id', 26)->nullable();
                $table->string('target_environment_id', 26)->nullable();
                $table->string('version_id', 26);
                // The source's live deployment the version was taken from, the target's new one
                $table->string('source_deployment_id', 26)->nullable();
                $table->string('deployment_id', 26)->nullable();
                // pending_approval | approved | deploying | deployed | rejected | cancelled | superseded | failed
                $table->string('status', 24);
                // The image the promotion pins (build image or CaaS image of the version)
                $table->string('image', 512)->nullable();
                // What changes in the target: code and config differences, names only (no values)
                $table->json('diff')->nullable();
                $table->text('message')->nullable();
                $table->string('requested_by', 64)->nullable();
                $table->string('requested_by_name')->nullable();
                $table->string('decided_by', 64)->nullable();
                $table->string('decided_by_name')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_comment')->nullable();
                $table->text('status_message')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'status']);
                $table->index('status');
            });
        }

        if (Schema::hasTable('deployments')) {
            Schema::table('deployments', function (Blueprint $table) {
                if (! Schema::hasColumn('deployments', 'config_snapshot')) {
                    $table->text('config_snapshot')->nullable();
                }
                if (! Schema::hasColumn('deployments', 'config_source')) {
                    $table->string('config_source', 16)->nullable();
                }
            });
        }

        if (Schema::hasTable('queued_deploys') && ! Schema::hasColumn('queued_deploys', 'config_snapshot')) {
            Schema::table('queued_deploys', function (Blueprint $table) {
                $table->text('config_snapshot')->nullable();
            });
        }

        foreach (self::ENVIRONMENT_COLUMNS as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'environment_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('environment_id', 26)->nullable()->index();
                });
            }
        }
    }

    public static function down(): void
    {
        foreach (self::ENVIRONMENT_COLUMNS as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'environment_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropIndex(['environment_id']);
                    $table->dropColumn('environment_id');
                });
            }
        }

        if (Schema::hasTable('queued_deploys') && Schema::hasColumn('queued_deploys', 'config_snapshot')) {
            Schema::table('queued_deploys', fn (Blueprint $table) => $table->dropColumn('config_snapshot'));
        }
        if (Schema::hasTable('deployments') && Schema::hasColumn('deployments', 'config_snapshot')) {
            Schema::table('deployments', fn (Blueprint $table) => $table->dropColumn(['config_snapshot', 'config_source']));
        }

        foreach (self::TABLES as $name) {
            Schema::dropIfExists($name);
        }
    }
}
