<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createProjects();
        $this->createVersions();
        $this->createDeployments();
        $this->createXaasConfigs();
        $this->createFaasConfigs();
        $this->createContainerConfigs();
        $this->createGateways();
    }

    private function createProjects(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug');
            $table->string('service_type');
            $table->string('runtime')->nullable();
            $table->text('description')->nullable();
            $table->string('git_path')->nullable();
            $table->timestamps();
            $table->char('knative_region_id', 26)->nullable();
            $table->string('runtime_version')->nullable();
            $table->boolean('is_region_demo')->default(false);
            $table->string('endpoint_type')->default('subdomain');
        });
    }

    private function createVersions(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->integer('number');
            $table->string('commit_id')->nullable();
            $table->string('commit_message')->nullable();
            $table->string('created_by');
            $table->json('changed_files')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->timestamps();
            $table->string('build_status')->default('ready');
            $table->text('build_error')->nullable();
            $table->string('build_token')->nullable();
            $table->string('build_image')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    private function createDeployments(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->char('version_id', 26);
            $table->string('status')->default('pending');
            $table->string('k8s_job_name')->nullable();
            $table->string('deployed_by')->nullable();
            $table->timestamps();
            $table->json('deploy_config')->nullable();
            $table->boolean('knative_ready')->nullable();
            $table->string('knative_message')->nullable();
            $table->timestamp('ready_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
        });
    }

    private function createXaasConfigs(): void
    {
        Schema::create('xaas_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('filename');
            $table->json('env_vars')->nullable();
            $table->integer('memory_mb')->default(128);
            $table->integer('timeout_seconds')->default(30);
            $table->string('trigger_type')->default('http');
            $table->string('schedule')->nullable();
            $table->timestamps();
            $table->string('cpu')->default('250m');
            $table->string('queue_stream')->nullable();
            $table->string('handler')->default('handler');
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    private function createFaasConfigs(): void
    {
        Schema::create('faas_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('handler');
            $table->json('env_vars')->nullable();
            $table->integer('memory_mb')->default(128);
            $table->integer('timeout_seconds')->default(30);
            $table->string('trigger_type')->default('http');
            $table->string('schedule')->nullable();
            $table->timestamps();
            $table->string('cpu')->default('250m');
            $table->string('queue_stream')->nullable();
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    private function createContainerConfigs(): void
    {
        Schema::create('container_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('image');
            $table->json('command')->nullable();
            $table->json('args')->nullable();
            $table->json('env_vars')->nullable();
            $table->json('ports')->nullable();
            $table->integer('memory_mb')->default(128);
            $table->string('cpu')->default('500m');
            $table->integer('timeout_seconds')->default(30);
            $table->string('image_pull_policy')->default('IfNotPresent');
            $table->string('image_pull_secret')->nullable();
            $table->string('trigger_type')->default('http');
            $table->string('schedule')->nullable();
            $table->timestamps();
            $table->string('queue_stream')->nullable();
            $table->boolean('scale_to_zero')->default(true);
            $table->integer('min_scale')->default(0);
            $table->integer('max_scale')->default(1);

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    private function createGateways(): void
    {
        Schema::create('gateways', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->char('knative_region_id', 26);
            $table->string('subdomain');
            $table->string('custom_domain')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('gateway_routes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('gateway_id', 26);
            $table->char('project_id', 26);
            $table->string('path');
            $table->json('methods')->nullable();
            $table->boolean('strip_prefix')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('gateway_id')->references('id')->on('gateways')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_routes');
        Schema::dropIfExists('gateways');
        Schema::dropIfExists('container_configs');
        Schema::dropIfExists('faas_configs');
        Schema::dropIfExists('xaas_configs');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('versions');
        Schema::dropIfExists('projects');
    }
};
