<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same columns as the central knative_regions (2026_09_30_000001_add_observability_managed_flags,
 * 2026_10_01_000001_add_loki_org_id): whether Prometheus/Loki was installed by RunR or provided
 * by the operator, + the tenant ID of an external Loki (X-Scope-OrgID). Tenant-owned regions
 * read/write these columns; the tenant copies of platform regions are filled by the sync.
 * Existing rows are true: RunR installed the observability stack of every region set up so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['prometheus_managed', 'loki_managed'] as $column) {
            if (! Schema::hasColumn('knative_regions', $column)) {
                Schema::table('knative_regions', function (Blueprint $table) use ($column) {
                    $table->boolean($column)->default(true);
                });
            }
        }

        if (! Schema::hasColumn('knative_regions', 'loki_org_id')) {
            Schema::table('knative_regions', function (Blueprint $table) {
                $table->string('loki_org_id', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['prometheus_managed', 'loki_managed', 'loki_org_id'] as $column) {
            if (Schema::hasColumn('knative_regions', $column)) {
                Schema::table('knative_regions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
