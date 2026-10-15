<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_10_200001_add_wasm_sandbox_to_knative_regions.php:
 * the WebAssembly support status of a region. Tenant-owned regions write it themselves; the tenant
 * copies of platform regions are filled by the central → tenant sync (TenantLocalRegion), so
 * deploys in tenant context see which regions offer the Wasm runtimes. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'sandbox_wasm_status' => fn (Blueprint $table) => $table->string('sandbox_wasm_status', 30)->default('not_installed'),
            'sandbox_wasm_status_message' => fn (Blueprint $table) => $table->text('sandbox_wasm_status_message')->nullable(),
            'sandbox_wasm_shims' => fn (Blueprint $table) => $table->json('sandbox_wasm_shims')->nullable(),
            'sandbox_wasm_nodes' => fn (Blueprint $table) => $table->json('sandbox_wasm_nodes')->nullable(),
        ];

        foreach ($columns as $column => $define) {
            if (! Schema::hasColumn('knative_regions', $column)) {
                Schema::table('knative_regions', function (Blueprint $table) use ($define) {
                    $define($table);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['sandbox_wasm_status', 'sandbox_wasm_status_message', 'sandbox_wasm_shims', 'sandbox_wasm_nodes'] as $column) {
            if (Schema::hasColumn('knative_regions', $column)) {
                Schema::table('knative_regions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
