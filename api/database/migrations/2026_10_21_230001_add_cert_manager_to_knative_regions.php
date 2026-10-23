<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cert-manager of a region (App\Services\CertManager\CertManagerService): its status and step log,
 * whether RunR installed it (true) or reuses the cluster's own (false), the version and namespace
 * it runs in, and the ClusterIssuer the region's custom domains are issued by — the tenants'
 * copies of a platform region read it from here (they do not carry the region's settings).
 * Additive: existing rows read "not_installed" until region:reconcile (CertManagerStep) installs
 * or adopts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('cert_manager_status', 30)->default('not_installed');
            $table->text('cert_manager_status_message')->nullable();
            $table->boolean('cert_manager_managed')->nullable();
            $table->string('cert_manager_version', 64)->nullable();
            $table->string('cert_manager_namespace', 63)->nullable();
            $table->string('cert_manager_issuer', 253)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn([
                'cert_manager_status', 'cert_manager_status_message', 'cert_manager_managed',
                'cert_manager_version', 'cert_manager_namespace', 'cert_manager_issuer',
            ]);
        });
    }
};
