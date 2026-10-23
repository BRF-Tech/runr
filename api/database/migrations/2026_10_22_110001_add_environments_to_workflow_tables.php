<?php

use App\Workflows\Database\WorkflowEnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Workflows per environment (docs/workflows.md#environments): environment_id / environment on the
 * workflow tables, one workflow app and one set of definitions per project and environment.
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0085_add_environments_to_workflow_tables.php —
 * both run App\Workflows\Database\WorkflowEnvironmentSchema.
 */
return new class extends Migration
{
    public function up(): void
    {
        WorkflowEnvironmentSchema::up();
    }

    public function down(): void
    {
        WorkflowEnvironmentSchema::down();
    }
};
