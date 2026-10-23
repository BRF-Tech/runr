<?php

use App\Workflows\Database\WorkflowEnvironmentSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_110001_add_environments_to_workflow_tables.php:
 * the organization's workflow apps, definitions, runs, events and usage per environment
 * (docs/workflows.md#environments).
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
