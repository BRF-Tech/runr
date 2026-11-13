<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_11_12_200001_pin_versionless_wasm_projects_to_4_1.php:
 * the tenant's wasm-rust and wasm-js projects without a runtime version are pinned to 4.1, so the
 * new default 4.2 of 0.7.0 does not move them to code they were not written for.
 */
return new class extends Migration
{
    public const RUNTIMES = ['wasm-rust', 'wasm-js'];

    public const VERSION = '4.1';

    public function up(): void
    {
        if (! Schema::hasTable('projects') || ! Schema::hasColumn('projects', 'runtime_version')) {
            return;
        }

        DB::table('projects')
            ->whereIn('runtime', self::RUNTIMES)
            ->where(fn ($q) => $q->whereNull('runtime_version')->orWhere('runtime_version', ''))
            ->update(['runtime_version' => self::VERSION]);
    }

    /** The pin stays: 4.1 is what those projects ran before the upgrade. */
    public function down(): void {}
};
