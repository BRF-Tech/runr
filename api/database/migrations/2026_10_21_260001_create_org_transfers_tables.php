<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization export / import (docs/org-export.md). Central tables with the owner of each row,
 * like the image uploads: the limit on running jobs counts every organization of the
 * installation, and an import into another organization of the same installation reads the
 * package of the export without walking the tenant schemas.
 *
 *  - org_transfers: one export (the package it wrote) or one import (the upload, the dry-run
 *    plan, the report), with status, phase and progress
 *  - org_transfer_items: what an import created per package entry (section + source key →
 *    target id): an import applied again after a failure skips what is done
 *
 * owner_key is the tenant id, or "platform" outside a tenant context (single-tenant installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_key', 64);
            $table->string('tenant_id')->nullable();
            $table->string('kind', 16);                         // export | import
            $table->string('status', 24);
            $table->string('phase', 32)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('message')->nullable();
            $table->json('options')->nullable();
            $table->json('estimate')->nullable();
            $table->json('destination')->nullable();
            $table->string('package_filename')->nullable();
            $table->unsignedBigInteger('package_size')->nullable();
            $table->string('package_sha256', 64)->nullable();
            $table->unsignedInteger('schema_version')->nullable();
            $table->string('source_runr_version', 64)->nullable();
            $table->json('package_source')->nullable();
            $table->unsignedBigInteger('upload_size')->nullable();
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->unsignedInteger('chunk_size')->nullable();
            $table->string('upload_sha256', 64)->nullable();
            $table->longText('plan')->nullable();
            $table->longText('report')->nullable();
            $table->string('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['owner_key', 'kind', 'status']);
            $table->index('status');
        });

        Schema::create('org_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->string('org_transfer_id', 26);
            $table->string('section', 32);
            $table->string('source_key', 191);
            $table->string('name')->nullable();
            $table->string('target_id')->nullable();
            $table->string('status', 16);                       // created | exists | skipped | failed | manual
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['org_transfer_id', 'section', 'source_key']);
            $table->foreign('org_transfer_id')->references('id')->on('org_transfers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_transfer_items');
        Schema::dropIfExists('org_transfers');
    }
};
