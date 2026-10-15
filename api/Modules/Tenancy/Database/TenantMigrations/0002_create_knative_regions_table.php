<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knative_regions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->longText('kubeconfig')->nullable();
            $table->unsignedInteger('total_cpu_m')->default(0);
            $table->unsignedInteger('total_memory_mb')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->boolean('is_synced')->default(false);
            $table->string('owner_tenant_id')->nullable();
            $table->string('region_mode', 20)->default('managed');
            // Knative statuses
            $table->string('knative_status', 30)->default('unknown');
            $table->text('knative_status_message')->nullable();
            $table->timestamp('knative_status_checked_at')->nullable();
            $table->json('selected_workers')->nullable();
            $table->json('knative_settings')->nullable();
            // Cluster
            $table->string('cluster_status')->default('unknown');
            $table->text('cluster_status_message')->nullable();
            $table->string('external_ip')->nullable();
            // Operator
            $table->string('knative_operator_status', 30)->default('not_installed');
            $table->text('knative_operator_status_message')->nullable();
            $table->string('knative_operator_namespace', 63)->default('knative-operator');
            // Serving
            $table->string('knative_serving_status', 30)->default('not_installed');
            $table->text('knative_serving_status_message')->nullable();
            $table->string('knative_serving_namespace', 63)->default('knative-serving');
            $table->string('knative_serving_ingress_class', 30)->default('traefik');
            $table->string('knative_serving_version')->nullable();
            // Eventing
            $table->string('knative_eventing_status', 30)->default('not_installed');
            $table->text('knative_eventing_status_message')->nullable();
            $table->string('knative_eventing_namespace', 63)->default('knative-eventing');
            $table->string('knative_eventing_version', 20)->nullable();
            // Redis
            $table->string('redis_mode', 20)->nullable();
            $table->string('redis_address')->nullable();
            $table->text('redis_password')->nullable();
            $table->string('knative_redis_status', 30)->nullable();
            $table->text('knative_redis_status_message')->nullable();
            // Registry
            $table->string('registry_mode', 20)->nullable();
            $table->string('registry_address')->nullable();
            $table->string('registry_username')->nullable();
            $table->text('registry_password')->nullable();
            $table->string('registry_storage_type', 10)->nullable();
            $table->json('registry_storage_config')->nullable();
            $table->string('registry_status', 30)->nullable();
            $table->text('registry_status_message')->nullable();
            $table->string('registry_namespace', 63)->default('runr-registry');
            // Project & Observability
            $table->string('project_namespace', 63)->default('runr');
            $table->string('prometheus_url')->nullable();
            $table->string('loki_url')->nullable();
            $table->string('loki_status')->nullable();
            // Demo & DNS
            $table->string('region_demo_status')->nullable();
            $table->boolean('dns_resolved')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knative_regions');
    }
};
