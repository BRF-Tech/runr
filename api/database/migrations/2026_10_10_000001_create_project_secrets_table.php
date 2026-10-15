<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secret environment variables of a project (docs/security.md, "Secret environment variables").
 * The value is encrypted at rest (App\Casts\Encrypted) and write-only through the API; deploys
 * deliver it through the Kubernetes Secret <slug>-secret-env and secretKeyRef env entries.
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0040_create_project_secrets_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_secrets')) {
            return;
        }

        Schema::create('project_secrets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_secrets');
    }
};
