<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private connections of a project to a Managed PostgreSQL database through
 * Skupper. Only non-secret metadata lives here — database name, user, password and the link
 * token are kept in Kubernetes (Secret / Skupper resources) on the region.
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0034_create_private_db_bindings_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_db_bindings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('resource_name', 63)->unique();
            $table->string('env_prefix', 32)->default('');
            $table->string('routing_key');
            $table->unsignedInteger('port')->default(5432);
            $table->string('sslmode', 16)->default('require');
            $table->string('link_kind', 16);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->unique(['project_id', 'env_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_db_bindings');
    }
};
