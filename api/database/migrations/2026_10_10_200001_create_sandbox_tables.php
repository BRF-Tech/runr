<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sandbox API (docs/sandbox-api.md). Central tables — like the runtime plugins, sandboxes of every
 * organization live here with their tenant_id, so the reaper, the warm pools and the quotas see all
 * of them without walking the tenant schemas.
 *
 *  - sandboxes: one row per sandbox pod, warm pool pods included (status warming / warm until
 *    claimed; a claim keeps the row and its id)
 *  - sandbox_processes: background processes started through the API
 *  - sandbox_events: lifecycle and activity log of a sandbox (create, exec, files, terminal, …)
 *  - sandbox_pools: warm pool definitions per region × runtime/version (× organization)
 *  - sandbox_region_settings: allowed sandbox classes, default class, runc opt-in, TTL and size ceilings per region
 *  - sandbox_quotas: organization quotas (tenant_id null = the defaults)
 *  - sandbox_usage_records: metered usage per organization × region × day
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandboxes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->string('created_by')->nullable();
            $table->string('knative_region_id');
            $table->string('namespace', 63);
            $table->string('pod_name', 63);
            $table->string('status', 16)->default('pending');
            $table->ulid('pool_id')->nullable();
            $table->boolean('from_pool')->default(false);
            $table->string('runtime', 32)->nullable();
            $table->string('runtime_version', 32)->nullable();
            $table->string('image', 512);
            $table->string('sandbox_class', 64)->default('runc');
            $table->unsignedInteger('cpu_m');
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('disk_mb');
            $table->unsignedInteger('pids_limit');
            $table->unsignedInteger('uid');
            $table->boolean('read_only_root')->default(true);
            $table->json('network')->nullable();
            $table->text('env')->nullable();                 // encrypted JSON
            $table->json('metadata')->nullable();
            $table->unsignedInteger('ttl_seconds');
            $table->unsignedInteger('idle_timeout_seconds')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('started_at')->nullable();      // running since (claim time for pool pods)
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 16)->nullable();
            $table->unsignedInteger('start_ms')->nullable();   // request → running
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['knative_region_id', 'status']);
            $table->index(['pool_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('pod_name');
        });

        Schema::create('sandbox_processes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('sandbox_id');
            $table->unsignedInteger('pid')->nullable();
            $table->text('command');
            $table->string('cwd', 1024)->nullable();
            $table->string('status', 16)->default('running');  // running | exited | killed
            $table->integer('exit_code')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['sandbox_id', 'status']);
        });

        Schema::create('sandbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('sandbox_id');
            $table->string('type', 32);
            $table->string('message', 1000)->nullable();
            $table->json('data')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['sandbox_id', 'created_at']);
        });

        Schema::create('sandbox_pools', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->string('knative_region_id');
            $table->string('runtime', 32);
            $table->string('runtime_version', 32)->nullable();
            $table->string('sandbox_class', 64)->nullable();   // null = the region's default class
            $table->unsignedInteger('cpu_m');
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('disk_mb');
            $table->unsignedInteger('size')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('misses')->default(0);
            $table->string('created_by')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'knative_region_id']);
        });

        Schema::create('sandbox_region_settings', function (Blueprint $table) {
            $table->string('knative_region_id')->primary();
            $table->boolean('enabled')->default(true);
            $table->json('allowed_classes')->nullable();        // null = every installed class
            $table->string('default_class', 64)->nullable();    // null = the strongest allowed
            $table->boolean('allow_runc')->default(false);
            $table->unsignedInteger('default_ttl_seconds')->nullable();
            $table->unsignedInteger('max_ttl_seconds')->nullable();
            $table->unsignedInteger('max_cpu_m')->nullable();
            $table->unsignedInteger('max_memory_mb')->nullable();
            $table->unsignedInteger('max_disk_mb')->nullable();
            $table->timestamps();
        });

        Schema::create('sandbox_quotas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id')->nullable()->unique();  // null = defaults for every organization without its own row
            $table->unsignedInteger('max_concurrent')->nullable();
            $table->unsignedInteger('max_cpu_m')->nullable();
            $table->unsignedInteger('max_memory_mb')->nullable();
            $table->unsignedBigInteger('max_sandbox_seconds_month')->nullable();
            $table->unsignedInteger('max_pool_size')->nullable();
            $table->json('allowed_classes')->nullable();        // null = whatever the region allows
            $table->timestamps();
        });

        Schema::create('sandbox_usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->string('knative_region_id');
            $table->date('record_date');
            $table->unsignedInteger('sandbox_count')->default(0);
            $table->unsignedBigInteger('sandbox_seconds')->default(0);
            $table->unsignedBigInteger('cpu_mc_ms')->default(0);
            $table->unsignedBigInteger('memory_mib_ms')->default(0);
            $table->decimal('raw_cost', 12, 6)->default(0);
            $table->decimal('multiplier', 5, 2)->default(1.0);
            $table->decimal('final_cost', 12, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['tenant_id', 'knative_region_id', 'record_date'], 'sandbox_usage_unique');
            $table->index('record_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_usage_records');
        Schema::dropIfExists('sandbox_quotas');
        Schema::dropIfExists('sandbox_region_settings');
        Schema::dropIfExists('sandbox_pools');
        Schema::dropIfExists('sandbox_events');
        Schema::dropIfExists('sandbox_processes');
        Schema::dropIfExists('sandboxes');
    }
};
