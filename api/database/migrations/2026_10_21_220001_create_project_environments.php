<?php

use App\Services\Environments\EnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Project environments (docs/environments.md): dev / staging / … next to the project's own
 * (production) workload, promotions between them with their approvals, per-environment secrets
 * (metadata only: their values live in the environment's region, <workload>-secret-env), and
 * environment_id on the deployments, binding attachments, custom domains, git deploys, queued
 * deploys and usage records of an environment. Deployments record the effective config they were
 * applied with (config_snapshot), which a rollback restores.
 *
 * Every tenant schema gets the same tables and columns (Modules/Tenancy/Database/TenantMigrations/0080).
 */
return new class extends Migration
{
    public function up(): void
    {
        EnvironmentSchema::up();
    }

    public function down(): void
    {
        EnvironmentSchema::down();
    }
};
