<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade to 0.7.0: wasm-rust and wasm-js get version 4.2 (Spin Rust SDK 8, esbuild 0.28) as the
 * default of new projects (docs/wasm.md#versions). A project without a runtime version runs the
 * runtime's default, so the existing wasm-rust and wasm-js projects without one are pinned to 4.1,
 * the version their code was written for: their next deploy builds on it as before. Moving one to
 * 4.2 is setting its runtime_version after porting its code (docs/upgrade.md).
 *
 * Tenant schemas: Modules/Tenancy/Database/TenantMigrations/0113_pin_versionless_wasm_projects_to_4_1.php
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
