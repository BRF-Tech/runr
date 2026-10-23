<?php

use App\Services\Secrets\RollbackCopies;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The downgrade guard of pass-through (App\Services\Secrets\RollbackCopies, docs/upgrade.md
 * "Rolling back"): project_secrets.rollback_copy marks a value that is the encrypted copy of the
 * region's value, kept so that RunR 0.5.10 — which builds the region's Secret from this column on
 * every deploy — can be installed again without writing empty values.
 *
 * An installation that never ran a release before pass-through has nothing to roll back to: it
 * starts finalized (the setting RollbackCopies::SETTING). One that did — the pass-through
 * migration ran in a later batch than migrations before it — keeps copies until the operator runs
 * `php artisan secrets:move-to-region --finalize`.
 *
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0105_add_rollback_copy_to_project_secrets.php
 * (the setting is the installation's, central only).
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

        if (! Schema::hasTable('settings') || DB::table('settings')->where('key', RollbackCopies::SETTING)->exists()) {
            return;
        }
        if (! $this->upgradedFromBeforePassThrough()) {
            DB::table('settings')->insert([
                'key' => RollbackCopies::SETTING,
                'value' => now()->toIso8601String(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_secrets') && Schema::hasColumn('project_secrets', 'rollback_copy')) {
            Schema::table('project_secrets', fn (Blueprint $table) => $table->dropColumn('rollback_copy'));
        }
    }

    /**
     * Migrations of an earlier batch than the pass-through migration's: this database ran a
     * release before pass-through (0.5.10 or earlier) that could be installed again.
     */
    private function upgradedFromBeforePassThrough(): bool
    {
        $table = config('database.migrations.table', 'migrations');
        $table = is_array($table) ? ($table['table'] ?? 'migrations') : $table;
        if (! Schema::hasTable($table)) {
            return false;
        }

        $batch = DB::table($table)->where('migration', '2026_10_21_200001_project_secrets_pass_through')->value('batch');
        if ($batch === null) {
            // Not recorded (a database put together by hand): more than one batch marks an upgrade
            return (int) DB::table($table)->min('batch') < (int) DB::table($table)->max('batch');
        }

        return DB::table($table)->where('batch', '<', (int) $batch)->exists();
    }
};
