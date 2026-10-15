<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hugging Face models (docs/huggingface.md). The same tables exist in every tenant schema
 * (Modules/Tenancy/Database/TenantMigrations/0046_create_ai_model_tables.php requires this file).
 *
 *  ai_models               a model: a Hub repository pinned to a revision, or uploaded / imported files
 *  ai_model_snapshots      an offline copy of a model in a region (model volume), made by a mirror
 *                          Job, an upload or an import
 *  ai_model_services       a model served by an engine as a RunR-managed Knative Service
 *  ai_model_bindings       a project using a model service (env vars, optional read-only weights)
 *  ai_model_uploads        chunked uploads of model files into a region (air-gapped regions)
 *  ai_hub_credentials      the organization's Hugging Face token (encrypted at rest)
 *  ai_model_service_usage  metered replica / GPU time of model services per day
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 63)->unique();
            $table->string('source', 16)->default('hub');       // hub | upload | pvc | oci
            $table->string('hub_id', 200)->nullable()->index(); // org/name
            $table->string('revision', 100)->default('main');   // as requested (branch, tag or commit)
            $table->string('revision_sha', 64)->nullable();     // commit the revision resolved to
            $table->string('task', 64)->nullable();             // pipeline tag
            $table->string('library', 64)->nullable();
            $table->string('license', 100)->nullable();
            $table->boolean('gated')->default(false);
            $table->boolean('private')->default(false);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('parameters')->nullable();
            $table->text('card_summary')->nullable();
            $table->json('metadata')->nullable();               // tags, downloads, likes, files
            $table->string('source_ref', 500)->nullable();      // PVC path or OCI reference of an import
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('ai_model_id', 26);
            $table->char('knative_region_id', 26);
            $table->string('source', 16);                       // hub | upload | pvc | oci
            $table->string('revision', 100);
            $table->string('path', 500);                        // relative to the region model volume
            $table->string('status', 20)->default('pending');   // pending | running | ready | failed | deleting
            $table->text('message')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('files_count')->nullable();
            $table->string('job_name', 63)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_model_id', 'knative_region_id', 'revision']);
            $table->foreign('ai_model_id')->references('id')->on('ai_models')->cascadeOnDelete();
        });

        Schema::create('ai_model_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('ai_model_id', 26)->index();
            $table->char('knative_region_id', 26)->index();
            $table->string('name', 40)->unique();
            $table->string('service_name', 63);
            $table->string('engine', 20);                       // vllm | tgi | tei | transformers | diffusers | custom
            $table->string('device', 8)->default('cpu');        // cpu | gpu
            $table->string('mode', 10)->default('online');      // online | offline
            $table->json('gpu')->nullable();                    // {count, type, memory_min}
            $table->string('cpu', 16)->default('1');
            $table->unsignedInteger('memory_mb')->default(2048);
            $table->unsignedInteger('min_scale')->default(0);
            $table->unsignedInteger('max_scale')->default(1);
            $table->boolean('scale_to_zero')->default(true);
            $table->unsignedInteger('timeout_seconds')->default(300);
            $table->string('image', 500)->nullable();           // engine image override
            $table->json('args')->nullable();                   // extra / replacement engine arguments
            $table->text('env_vars')->nullable();               // encrypted JSON (App\Casts\Encrypted:array)
            $table->unsignedInteger('port')->nullable();
            $table->string('health_path', 200)->nullable();
            $table->string('served_name', 100);
            $table->string('status', 20)->default('stopped');   // stopped | waiting_snapshot | deploying | ready | failed
            $table->text('status_message')->nullable();
            $table->char('snapshot_id', 26)->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_bindings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->char('ai_model_service_id', 26);
            $table->string('alias', 32);
            $table->boolean('mount_weights')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'alias']);
            $table->unique(['project_id', 'ai_model_service_id']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('ai_model_service_id')->references('id')->on('ai_model_services')->cascadeOnDelete();
        });

        Schema::create('ai_model_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('ai_model_id', 26);
            $table->char('knative_region_id', 26);
            $table->string('status', 20)->default('preparing'); // preparing | ready | completing | completed | failed | aborted
            $table->json('files');                              // [{path, size, sha256, uploaded_bytes}]
            $table->unsignedInteger('chunk_size');
            $table->string('receiver_name', 63)->nullable();
            $table->char('snapshot_id', 26)->nullable();
            $table->text('message')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('ai_model_id')->references('id')->on('ai_models')->cascadeOnDelete();
        });

        Schema::create('ai_hub_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 32)->unique();           // huggingface
            $table->text('token');                              // encrypted cast
            $table->string('hint', 8)->nullable();              // last characters, shown in the UI
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_service_usage', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('ai_model_service_id', 26);
            $table->char('knative_region_id', 26);
            $table->date('record_date');
            $table->unsignedBigInteger('replica_ms')->default(0);
            $table->unsignedBigInteger('gpu_unit_ms')->default(0);
            $table->unsignedBigInteger('cpu_mc_ms')->default(0);
            $table->unsignedBigInteger('memory_mib_ms')->default(0);
            $table->timestamp('last_sampled_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_model_service_id', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_service_usage');
        Schema::dropIfExists('ai_hub_credentials');
        Schema::dropIfExists('ai_model_uploads');
        Schema::dropIfExists('ai_model_bindings');
        Schema::dropIfExists('ai_model_services');
        Schema::dropIfExists('ai_model_snapshots');
        Schema::dropIfExists('ai_models');
    }
};
