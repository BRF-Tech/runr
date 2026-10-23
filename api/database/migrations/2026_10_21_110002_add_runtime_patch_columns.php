<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What runs on which runtime patch (docs/runtimes.md#runtime-patches):
 *
 *  projects      runtime_patch_pin       a patch id (one of the version's last five) or, after a
 *                                        failed migration was rolled back, the digest it went back to
 *                runtime_patch_id /      the patch and image digest the live deployment runs
 *                runtime_digest          (written when a deployment becomes active)
 *  deployments   runtime_image / runtime_digest / runtime_patch_id   what the deployment applied
 *                                        (set before the apply: a rollback names the digest)
 *  versions      runtime_digest / runtime_patch_id   the base a prebuilt version was built FROM;
 *                runtime_base_pin        the base digest its next build must use (a rollback)
 *
 * runtime_patch_migrations: one row per redeploy onto a patch — "apply patch", a pin, the
 * automatic migration at a deadline — with the digest it came from, so a failed one is rolled
 * back once and the deadline job does not try the same patch again.
 *
 * Every tenant schema gets the same (Modules/Tenancy/Database/TenantMigrations/0068).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                if (! Schema::hasColumn('projects', 'runtime_patch_pin')) {
                    $table->string('runtime_patch_pin', 128)->nullable();
                    $table->string('runtime_patch_pinned_by')->nullable();
                }
                if (! Schema::hasColumn('projects', 'runtime_patch_id')) {
                    $table->string('runtime_patch_id', 128)->nullable();
                    $table->string('runtime_digest', 80)->nullable();
                }
            });
        }

        if (Schema::hasTable('deployments') && ! Schema::hasColumn('deployments', 'runtime_digest')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->string('runtime_image', 500)->nullable();
                $table->string('runtime_digest', 80)->nullable();
                $table->string('runtime_patch_id', 128)->nullable();
            });
        }

        if (Schema::hasTable('versions') && ! Schema::hasColumn('versions', 'runtime_digest')) {
            Schema::table('versions', function (Blueprint $table) {
                $table->string('runtime_digest', 80)->nullable();
                $table->string('runtime_patch_id', 128)->nullable();
                $table->string('runtime_base_pin', 80)->nullable();
            });
        }

        if (! Schema::hasTable('runtime_patch_migrations') && Schema::hasTable('projects')) {
            Schema::create('runtime_patch_migrations', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('project_id', 26);
                $table->string('patch_id', 128)->nullable();
                // apply | pin | auto | force
                $table->string('trigger', 16);
                // started | succeeded | failed | rolled_back
                $table->string('status', 16)->default('started');
                $table->string('version_id', 26)->nullable();
                $table->string('deployment_id', 26)->nullable();
                $table->string('queued_deploy_id', 26)->nullable();
                $table->string('previous_digest', 80)->nullable();
                $table->string('previous_patch_id', 128)->nullable();
                $table->string('rollback_deployment_id', 26)->nullable();
                $table->string('initiated_by')->nullable();
                $table->text('message')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'patch_id']);
                $table->index('status');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_patch_migrations');

        if (Schema::hasColumn('versions', 'runtime_digest')) {
            Schema::table('versions', function (Blueprint $table) {
                $table->dropColumn(['runtime_digest', 'runtime_patch_id', 'runtime_base_pin']);
            });
        }
        if (Schema::hasColumn('deployments', 'runtime_digest')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->dropColumn(['runtime_image', 'runtime_digest', 'runtime_patch_id']);
            });
        }
        if (Schema::hasColumn('projects', 'runtime_patch_pin')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn(['runtime_patch_pin', 'runtime_patch_pinned_by', 'runtime_patch_id', 'runtime_digest']);
            });
        }
    }
};
