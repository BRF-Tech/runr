<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WebAssembly support of a region (App\Services\Wasm\WasmInstallService): install status and
 * step log, the runwasi shims installed (["spin", "wasmtime"]) and the nodes selected for them
 * (null = every eligible node). Additive: existing rows read "not_installed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('sandbox_wasm_status', 30)->default('not_installed');
            $table->text('sandbox_wasm_status_message')->nullable();
            $table->json('sandbox_wasm_shims')->nullable();
            $table->json('sandbox_wasm_nodes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['sandbox_wasm_status', 'sandbox_wasm_status_message', 'sandbox_wasm_shims', 'sandbox_wasm_nodes']);
        });
    }
};
