<?php

use App\Workflows\Database\WorkflowSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Durable workflow engine tables in every tenant schema (docs/workflows.md § Data model).
 * Central copy: database/migrations/2026_10_10_000001_create_workflow_tables.php.
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
