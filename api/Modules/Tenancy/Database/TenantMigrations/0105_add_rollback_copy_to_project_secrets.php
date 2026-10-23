<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The downgrade guard of pass-through (App\Services\Secrets\RollbackCopies, docs/upgrade.md
 * "Rolling back"): project_secrets.rollback_copy marks a value that is the encrypted copy of the
 * region's value, kept so that RunR 0.5.10 can be installed again without writing empty values.
 * Whether copies are kept is the installation's setting (central).
 *
 * Central copy: database/migrations/2026_10_22_500001_add_rollback_copy_to_project_secrets.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_secrets') && ! Schema::hasColumn('project_secrets', 'rollback_copy')) {
            Schema::table('project_secrets', function (Blueprint $table) {
                $table->boolean('rollback_copy')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_secrets') && Schema::hasColumn('project_secrets', 'rollback_copy')) {
            Schema::table('project_secrets', fn (Blueprint $table) => $table->dropColumn('rollback_copy'));
        }
    }
};
