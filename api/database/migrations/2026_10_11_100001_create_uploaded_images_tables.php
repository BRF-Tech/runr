<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded images (docs/images.md). Central tables with the owner of each row — like the runtime
 * plugins: platform runtimes reference platform uploads and the quota counts an organization's
 * uploads across regions without walking the tenant schemas.
 *
 *  - image_uploads: one chunked upload into a region (the receiver Job, bytes received, status)
 *  - uploaded_images: what an upload pushed into a region registry (repository, tag, digest,
 *    media type, size, platforms), listed in Settings → Images and used by CaaS projects,
 *    runtime versions (`upload:`) and wasm-exec functions
 *
 * owner_key is the tenant id, or "platform" for uploads outside a tenant context (single-tenant
 * installs, platform administrators); repository + tag are unique per owner and region.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_key', 64);
            $table->string('tenant_id')->nullable();
            $table->string('knative_region_id');
            $table->string('repository', 128);
            $table->string('tag', 128);
            $table->string('registry_repository', 255);
            $table->string('digest', 80);
            $table->string('media_type', 128);
            $table->string('config_media_type', 128)->nullable();
            $table->string('kind', 16)->default('image');       // image | wasm | spin
            $table->string('source_format', 16);                // docker | oci | wasm
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('archive_bytes')->default(0);
            $table->string('archive_sha256', 64)->nullable();
            $table->json('platforms')->nullable();
            $table->unsignedInteger('layers')->default(0);
            $table->string('source_filename', 255)->nullable();
            $table->string('uploaded_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['owner_key', 'knative_region_id', 'repository', 'tag'], 'uploaded_images_ref_unique');
            $table->index(['owner_key', 'kind']);
            $table->index(['knative_region_id', 'registry_repository', 'digest'], 'uploaded_images_digest_index');
        });

        Schema::create('image_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_key', 64);
            $table->string('tenant_id')->nullable();
            $table->string('knative_region_id');
            $table->string('repository', 128);
            $table->string('tag', 128);
            $table->string('filename', 255);
            $table->string('format', 16)->default('auto');      // auto | docker | oci | wasm
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->unsignedInteger('chunk_size');
            $table->string('status', 16)->default('preparing'); // preparing | ready | verifying | pushing | completed | failed | aborted
            $table->string('receiver_name', 63)->nullable();
            $table->text('message')->nullable();
            $table->ulid('uploaded_image_id')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_key', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_uploads');
        Schema::dropIfExists('uploaded_images');
    }
};
