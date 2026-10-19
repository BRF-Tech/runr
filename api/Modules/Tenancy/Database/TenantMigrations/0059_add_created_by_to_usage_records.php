<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * created_by on the tenant's usage records (docs/billing.md): usage that belongs to no project —
 * model services, bindings — is billed to the user who created the resource. The central tables get
 * the same column (database/migrations/2026_10_19_100002).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['usage_records', 'binding_usage_records'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'created_by')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('created_by')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['usage_records', 'binding_usage_records'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'created_by')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('created_by');
                });
            }
        }
    }
};
