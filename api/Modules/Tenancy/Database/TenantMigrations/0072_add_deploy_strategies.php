<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_21_150001_add_deploy_strategies.php: the deploy
 * strategy of the organization's projects (config tables) and their blue-green / canary rollouts
 * (App\Models\DeploymentRollout), next to the projects and deployments.
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

        if (Schema::hasTable('deployment_rollouts') || ! Schema::hasTable('projects')) {
            return;
        }

        Schema::create('deployment_rollouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->char('deployment_id', 26);
            $table->char('stable_deployment_id', 26)->nullable();
            $table->char('region_id', 26)->nullable();
            $table->string('strategy', 16);
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
