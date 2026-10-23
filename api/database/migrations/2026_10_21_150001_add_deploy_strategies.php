<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deploy strategies (docs/deploy-strategies.md):
 *  - xaas / faas / container configs: deploy_strategy, the project's strategy and its options
 *    (App\Services\Rollouts\DeployStrategy) — null is a direct deploy, as before
 *  - deployment_rollouts: a blue-green or canary rollout of a deployment — the revision it
 *    brings, the one that served before, the traffic split, the steps and what happened
 *    (App\Models\DeploymentRollout)
 *
 * Every tenant schema gets the same (Modules/Tenancy/Database/TenantMigrations/0072).
 */
return new class extends Migration
{
    private const CONFIG_TABLES = ['xaas_configs', 'faas_configs', 'container_configs'];

    public function up(): void
    {
        foreach (self::CONFIG_TABLES as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'deploy_strategy')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->json('deploy_strategy')->nullable();
                });
            }
        }

        if (Schema::hasTable('deployment_rollouts')) {
            return;
        }

        Schema::create('deployment_rollouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            // the new deployment and the one that served before (the previous one once promoted)
            $table->char('deployment_id', 26);
            $table->char('stable_deployment_id', 26)->nullable();
            // the region whose Knative Service carries the traffic split
            $table->char('region_id', 26)->nullable();
            // blue_green | canary
            $table->string('strategy', 16);
            // pending | progressing | waiting_promotion | promoted | aborted | rolled_back | failed
            $table->string('status', 24)->default('pending');
            $table->json('options')->nullable();
            $table->string('revision', 63)->nullable();
            $table->string('stable_revision', 63)->nullable();
            $table->string('tag', 16);
            $table->unsignedTinyInteger('traffic_percent')->default(0);
            $table->smallInteger('step_index')->nullable();
            $table->timestamp('step_started_at')->nullable();
            $table->json('health')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('keep_until')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('events')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('deployment_id');
            $table->index('stable_deployment_id');
            $table->index(['status', 'next_check_at']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_rollouts');

        foreach (self::CONFIG_TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'deploy_strategy')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('deploy_strategy');
                });
            }
        }
    }
};
