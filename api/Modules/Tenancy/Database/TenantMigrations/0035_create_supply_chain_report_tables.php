<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of the report tables of database/migrations/2026_10_10_300001_create_supply_chain_tables.php
 * (security reports, SBOM documents and policy overrides of the tenant's project versions).
 * Region settings and tenant policies are platform-wide and stay in the central schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_security_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject_type', 16);
            $table->string('subject_id', 26);
            $table->string('project_id', 26)->nullable()->index();
            $table->string('version_id', 26)->nullable()->index();
            $table->string('knative_region_id', 26)->index();
            $table->string('image_kind', 16);
            $table->string('image_ref');
            $table->string('image_digest', 80)->nullable();

            $table->string('status', 16)->default('pending');
            $table->string('job_name')->nullable();
            $table->json('steps')->nullable();
            $table->json('step_results')->nullable();
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
            $table->json('scanner')->nullable();
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
            $table->string('format', 24);
            $table->longText('content');
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

    public function down(): void
    {
        Schema::dropIfExists('security_policy_overrides');
        Schema::dropIfExists('image_sbom_documents');
        Schema::dropIfExists('image_security_reports');
    }
};
