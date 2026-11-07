<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime sources (docs/runtimes.md#runtime-sources): where runtimes come from besides one-by-one
 * registration — a signed OCI catalog of manifests or a Git repository of runtimes/<id>/runtime.yaml,
 * public or private, connected by a platform administrator (for everyone) or by an organization
 * (for itself, while the platform allows custom runtimes). RunR's own default catalog
 * (ghcr.io/brf-tech/runr/runtime-catalog:stable) is the row with is_default; its runtimes are the
 * built-in ones, the bundled resources/runtimes/builtin.json is only its offline fallback.
 *
 *  runtime_sources          one row per source: kind, URL / ref / path, public key, patch feed,
 *                           credentials (encrypted, App\Casts\Encrypted:array, never in an answer),
 *                           sync state (status, revision = manifest digest or commit, problems)
 *  runtimes                 source_id / source_path: the source a runtime was synced from;
 *                           orphaned_at: it left its source (its projects keep running, a sunset
 *                           follows after the grace period)
 *  executor_images          deprecated_at / sunset_at: the lifecycle of the manifest (version,
 *                           runtime) and of an orphaned runtime, next to the end of life (eol_at)
 *  runtime_patches,         source_id: patches of a source's own runtimes, from its signed catalog
 *  runtime_patch_feeds      or patch feed (null: RunR's patch feed)
 *
 * All central: runtimes are platform-wide (custom ones carry their tenant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('runtime_sources')) {
            Schema::create('runtime_sources', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('name', 64);
                // oci-catalog | git
                $table->string('kind', 16);
                // platform | organization
                $table->string('scope', 16);
                $table->string('tenant_id')->nullable();
                $table->string('url', 512);
                $table->string('ref', 255)->nullable();
                $table->string('path', 255)->nullable();
                // cosign public key (PEM) the catalog and the patch feed must be signed with
                $table->text('public_key')->nullable();
                // OCI reference of the source's signed patch feed
                $table->string('patch_feed', 512)->nullable();
                // the registry the registry credentials are for (private catalog and runtime images)
                $table->string('registry_host', 255)->nullable();
                $table->text('credentials')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('interval_minutes')->default(60);
                // never | ok | partial | unreachable | not_found | auth_failed | invalid_signature | invalid_catalog | disabled
                $table->string('status', 24)->default('never');
                $table->text('error')->nullable();
                $table->json('problems')->nullable();
                // manifest digest (oci-catalog) or commit (git) of the last good read
                $table->string('revision', 80)->nullable();
                $table->string('rejected_revision', 80)->nullable();
                $table->boolean('signature_verified')->default(false);
                $table->unsignedInteger('runtimes_count')->default(0);
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();

                $table->index(['scope', 'tenant_id']);
                $table->index('is_default');
            });
        }

        if (Schema::hasTable('runtimes') && ! Schema::hasColumn('runtimes', 'source_id')) {
            Schema::table('runtimes', function (Blueprint $table) {
                $table->string('source_id', 26)->nullable()->index();
                $table->string('source_path', 255)->nullable();
                $table->timestamp('orphaned_at')->nullable();
            });
        }

        if (Schema::hasTable('executor_images') && ! Schema::hasColumn('executor_images', 'sunset_at')) {
            Schema::table('executor_images', function (Blueprint $table) {
                $table->date('deprecated_at')->nullable();
                $table->date('sunset_at')->nullable();
            });
        }

        foreach (['runtime_patches', 'runtime_patch_feeds'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'source_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('source_id', 26)->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['runtime_patches', 'runtime_patch_feeds'] as $name) {
            if (Schema::hasColumn($name, 'source_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropIndex(['source_id']);
                    $table->dropColumn('source_id');
                });
            }
        }
        if (Schema::hasColumn('executor_images', 'sunset_at')) {
            Schema::table('executor_images', function (Blueprint $table) {
                $table->dropColumn(['deprecated_at', 'sunset_at']);
            });
        }
        if (Schema::hasColumn('runtimes', 'source_id')) {
            Schema::table('runtimes', function (Blueprint $table) {
                $table->dropIndex(['source_id']);
                $table->dropColumn(['source_id', 'source_path', 'orphaned_at']);
            });
        }
        Schema::dropIfExists('runtime_sources');
    }
};
