<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Software supply chain + data residency (docs/supply-chain.md).
 *
 * Platform-wide (central schema only — reached from tenant context through the central
 * connection, SupplyChainDb):
 *  - region_supply_chain_settings: per region SBOM / scan / policy gate / signing key /
 *    admission / data location. The private signing key and its password are stored with
 *    Laravel's encrypted cast and never leave the API.
 *  - tenant_security_policies: per tenant data residency (allowed regions / locations, default
 *    region) and an optional stricter policy gate.
 *
 * Reports (central for single-tenant installs and runtime images; tenant copy in
 * Modules/Tenancy/Database/TenantMigrations/0035_create_supply_chain_report_tables.php for the
 * versions of tenant projects):
 *  - image_security_reports, image_sbom_documents, security_policy_overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_supply_chain_settings', function (Blueprint $table) {
            $table->string('knative_region_id', 26)->primary();
            $table->string('owner_tenant_id')->nullable();   // tenant-owned region: the tenant to run its jobs in

            // Column default off: rows written for regions that existed before the supply chain
            // (2026_10_10_400001) keep them off; a new region starts on (model defaults)
            $table->boolean('sbom_enabled')->default(false);
            $table->boolean('scan_enabled')->default(false);
            $table->boolean('scan_external_images')->default(false);
            $table->unsignedInteger('rescan_interval_hours')->default(24);

            $table->string('scanner_db_mode', 16)->default('online');  // online | mirror | offline
            $table->string('scanner_db_source')->nullable();
            $table->string('scanner_db_status', 16)->default('none'); // none | refreshing | ready | failed
            $table->timestamp('scanner_db_updated_at')->nullable();    // DB build time (trivy metadata)
            $table->timestamp('scanner_db_refreshed_at')->nullable();  // last mirror run
            $table->text('scanner_db_message')->nullable();
            $table->string('scanner_db_job')->nullable();

            $table->string('gate_mode', 16)->default('off');           // off | warn | enforce
            $table->unsignedInteger('gate_max_critical')->nullable()->default(0);
            $table->unsignedInteger('gate_max_high')->nullable();
            $table->boolean('gate_only_fixable')->default(false);
            $table->boolean('gate_require_scan')->default(false);

            $table->boolean('signing_enabled')->default(false);
            $table->boolean('verify_on_deploy')->default(true);
            $table->boolean('attest_enabled')->default(true);
            $table->string('signing_status', 16)->default('none');     // none | generating | ready | failed
            $table->text('signing_public_key')->nullable();
            $table->string('signing_key_fingerprint', 64)->nullable();
            $table->longText('signing_private_key')->nullable();       // encrypted cast (cosign encrypted key)
            $table->text('signing_key_password')->nullable();          // encrypted cast
            $table->timestamp('signing_key_created_at')->nullable();
            $table->json('signing_retired_keys')->nullable();          // [{public_key, fingerprint, retired_at}]
            $table->text('signing_message')->nullable();
            $table->string('signing_job')->nullable();

            $table->string('admission_mode', 16)->default('off');      // off | warn | enforce
            $table->string('admission_status', 16)->default('not_installed'); // not_installed | installing | ready | failed | removing
            $table->text('admission_message')->nullable();
            $table->boolean('admission_require_sbom')->default(false);
            $table->json('admission_exempt_images')->nullable();
            $table->string('caas_policy', 16)->default('any');         // any | allowlist | signed
            $table->json('caas_allowed_registries')->nullable();
            $table->json('caas_trusted_keys')->nullable();

            $table->string('data_location', 64)->nullable();           // residency label, e.g. "TR" or "EU-DE"

            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_security_policies', function (Blueprint $table) {
            $table->string('tenant_id')->primary();
            $table->boolean('residency_enforced')->default(true);
            $table->json('allowed_region_ids')->nullable();
            $table->json('allowed_locations')->nullable();
            $table->string('default_region_id', 26)->nullable();

            // Tenant policy gate: null = the region's policy alone
            $table->string('gate_mode', 16)->nullable();
            $table->unsignedInteger('gate_max_critical')->nullable();
            $table->unsignedInteger('gate_max_high')->nullable();
            $table->boolean('gate_require_scan')->nullable();

            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        $this->createReportTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('security_policy_overrides');
        Schema::dropIfExists('image_sbom_documents');
        Schema::dropIfExists('image_security_reports');
        Schema::dropIfExists('tenant_security_policies');
        Schema::dropIfExists('region_supply_chain_settings');
    }

    private function createReportTables(): void
    {
        Schema::create('image_security_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject_type', 16);                 // version | runtime_build
            $table->string('subject_id', 26);
            $table->string('project_id', 26)->nullable()->index();
            $table->string('version_id', 26)->nullable()->index();
            $table->string('knative_region_id', 26)->index();
            $table->string('image_kind', 16);                   // built | runtime | external
            $table->string('image_ref');
            $table->string('image_digest', 80)->nullable();

            $table->string('status', 16)->default('pending');   // pending | running | completed | failed
            $table->string('job_name')->nullable();
            $table->json('steps')->nullable();                  // requested steps
            $table->json('step_results')->nullable();           // step → {status, message}
            $table->string('sbom_status', 16)->default('pending');
            $table->string('scan_status', 16)->default('pending');
            $table->string('signature_status', 16)->default('pending');
            $table->string('attestation_status', 16)->default('pending');
            $table->string('referrer_status', 16)->default('pending');

            $table->json('sbom_summary')->nullable();
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);
            $table->unsignedInteger('fixable_critical_count')->default(0);
            $table->unsignedInteger('fixable_high_count')->default(0);
            $table->unsignedInteger('findings_total')->default(0);
            $table->json('findings')->nullable();
            $table->json('scanner')->nullable();                // trivy version, DB updated at, os
            $table->timestamp('scanned_at')->nullable();

            $table->timestamp('signed_at')->nullable();
            $table->string('signature_key_fingerprint', 64)->nullable();

            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('image_sbom_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('report_id', 26)->index();
            $table->string('format', 24);                       // spdx-json | cyclonedx-json
            $table->longText('content');                        // base64(gzip(document))
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamps();

            $table->unique(['report_id', 'format']);
        });

        Schema::create('security_policy_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('project_id', 26)->index();
            $table->string('version_id', 26);
            $table->string('deployment_id', 26)->nullable();
            $table->string('report_id', 26)->nullable();
            $table->string('user_id')->nullable();
            $table->string('username')->nullable();
            $table->text('reason');
            $table->json('violations');
            $table->timestamps();
        });
    }
};
