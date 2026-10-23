<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_21_230001_add_cert_manager_to_knative_regions.php:
 * cert-manager of a region. A tenant's own region installs it in the tenant's schema; the tenant
 * copies of platform regions get its status and ClusterIssuer from the central → tenant sync
 * (TenantLocalRegion), so the custom domains of tenant projects there know whether TLS mode
 * cert-manager is offered and which issuer it uses. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knative_regions')) {
            return;
        }

        $columns = [
            'cert_manager_status' => fn (Blueprint $table) => $table->string('cert_manager_status', 30)->default('not_installed'),
            'cert_manager_status_message' => fn (Blueprint $table) => $table->text('cert_manager_status_message')->nullable(),
            'cert_manager_managed' => fn (Blueprint $table) => $table->boolean('cert_manager_managed')->nullable(),
            'cert_manager_version' => fn (Blueprint $table) => $table->string('cert_manager_version', 64)->nullable(),
            'cert_manager_namespace' => fn (Blueprint $table) => $table->string('cert_manager_namespace', 63)->nullable(),
            'cert_manager_issuer' => fn (Blueprint $table) => $table->string('cert_manager_issuer', 253)->nullable(),
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
        if (! Schema::hasTable('knative_regions')) {
            return;
        }

        foreach (['cert_manager_status', 'cert_manager_status_message', 'cert_manager_managed', 'cert_manager_version', 'cert_manager_namespace', 'cert_manager_issuer'] as $column) {
            if (Schema::hasColumn('knative_regions', $column)) {
                Schema::table('knative_regions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
