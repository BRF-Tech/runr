<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Loki tenant ID for an operator-supplied (loki_managed = false) Loki running with
 * multi-tenancy enabled: sent as the X-Scope-OrgID header on every log query. Null = no
 * header (single-tenant Loki, RunR's own loki-stack). Tenant schemas get the same column
 * in TenantMigrations/0023.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('knative_regions', 'loki_org_id')) {
            return;
        }

        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('loki_org_id', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn('loki_org_id');
        });
    }
};
