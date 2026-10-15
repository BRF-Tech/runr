<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bindings (docs/bindings.md): managed KV / object storage / Postgres instances and external
 * credentials that projects attach to, their project attachments, the region data services they
 * run on and their usage records. Credentials are stored encrypted (Laravel `encrypted` casts).
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0035_create_bindings_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bindings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 30)->unique();
            $table->string('resource_name', 63)->unique();
            $table->string('type', 16);
            $table->string('plan', 16)->default('shared');
            $table->foreignUlid('knative_region_id')->nullable()->constrained('knative_regions')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('status_message')->nullable();
            $table->json('settings')->nullable();
            $table->text('credentials')->nullable();
            $table->text('previous_credentials')->nullable();
            $table->unsignedBigInteger('usage_bytes')->default(0);
            $table->timestamp('usage_measured_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('delete_requested_at')->nullable();
            $table->timestamp('purge_at')->nullable()->index();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['knative_region_id', 'type']);
        });

        Schema::create('binding_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('binding_id')->constrained('bindings')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('env_prefix', 32)->default('');
            $table->timestamps();

            $table->unique(['binding_id', 'project_id']);
            $table->index('project_id');
        });

        Schema::create('binding_region_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('knative_region_id', 26);
            $table->string('service', 16);
            $table->string('mode', 16)->default('managed');
            $table->string('status', 16)->default('not_installed');
            $table->text('status_message')->nullable();
            $table->json('config')->nullable();
            $table->text('secrets')->nullable();
            $table->timestamps();

            $table->unique(['knative_region_id', 'service']);
        });

        Schema::create('binding_usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('binding_id', 26);
            $table->string('binding_name', 30);
            $table->string('type', 16);
            $table->string('plan', 16);
            $table->char('region_id', 26)->nullable();
            $table->date('record_date')->index();
            $table->decimal('storage_gb_hours', 16, 6)->default(0);
            $table->decimal('instance_hours', 12, 4)->default(0);
            $table->unsignedBigInteger('peak_bytes')->default(0);
            $table->decimal('raw_cost', 12, 6)->default(0);
            $table->decimal('final_cost', 12, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['binding_id', 'record_date']);
            $table->index(['region_id', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binding_usage_records');
        Schema::dropIfExists('binding_region_services');
        Schema::dropIfExists('binding_attachments');
        Schema::dropIfExists('bindings');
    }
};
