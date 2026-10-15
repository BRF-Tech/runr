<?php

use App\Workflows\Database\WorkflowSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Durable workflow engine tables (docs/workflows.md § Data model).
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0040_create_workflow_tables.php —
 * both run the same App\Workflows\Database\WorkflowSchema.
 */
return new class extends Migration
{
    public function up(): void
    {
        WorkflowSchema::up();
    }

    public function down(): void
    {
        WorkflowSchema::down();
    }
};
