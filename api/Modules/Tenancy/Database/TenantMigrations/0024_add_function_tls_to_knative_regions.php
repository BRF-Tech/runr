<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same columns as the central knative_regions (2026_09_30_000001_add_function_tls): the region's
 * function-domain TLS setting. PUT /knative-regions/{id}/function-tls writes these columns for a
 * tenant-owned region; the tenant copies of platform regions are filled by the sync.
 * Nullable only: existing rows are not affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'function_tls_mode' => fn (Blueprint $table) => $table->string('function_tls_mode')->nullable(),
            'function_tls_secret_name' => fn (Blueprint $table) => $table->string('function_tls_secret_name')->nullable(),
            'function_tls_expires_at' => fn (Blueprint $table) => $table->dateTime('function_tls_expires_at')->nullable(),
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
        foreach (['function_tls_mode', 'function_tls_secret_name', 'function_tls_expires_at'] as $column) {
            if (Schema::hasColumn('knative_regions', $column)) {
                Schema::table('knative_regions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
