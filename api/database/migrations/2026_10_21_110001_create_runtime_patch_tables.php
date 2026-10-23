<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime patches (docs/runtimes.md#runtime-patches): the signed patch feed RunR reads
 * (App\Services\Runtimes\Patches\PatchFeedSync) and what it says.
 *
 *  runtime_patch_feeds     the feed's state: reference, manifest digest, last check / fetch,
 *                          signature result, the platform's auto-migration policy
 *  runtime_patches         one row per patch tag of a runtime version (exec-php:8.3.36-r1): level,
 *                          reason, CVEs, digest, deadline (and a platform administrator's extension)
 *  platform_advisories     RunR releases the feed announces (banner for platform administrators)
 *  runtime_patch_notices   who was told about what (one row per subject and recipient: the
 *                          notifications go out once)
 *
 * executor_images.digest: the digest of the newest patch of the version (what the floating tag
 * points to). Projects, deployments and versions get the patch columns of
 * 2026_10_21_110002_add_runtime_patch_columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('runtime_patch_feeds')) {
            Schema::create('runtime_patch_feeds', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('reference')->unique();
                // ok | invalid_signature | invalid_schema | unreachable | disabled
                $table->string('status', 32)->default('disabled');
                $table->text('error')->nullable();
                $table->string('manifest_digest', 80)->nullable();
                $table->boolean('signature_verified')->default(false);
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('fetched_at')->nullable();
                // The manifest digest of the last feed that was refused (platform administrators are told once per digest)
                $table->string('rejected_digest', 80)->nullable();
                // null: config('runtime_patches.auto_migrate')
                $table->boolean('auto_migrate')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('runtime_patches')) {
            Schema::create('runtime_patches', function (Blueprint $table) {
                $table->string('id', 128)->primary();
                $table->string('runtime', 64);
                $table->string('version', 64);
                $table->string('tag', 128);
                $table->string('image');
                $table->string('digest', 80);
                // routine | security | critical | zero-day
                $table->string('level', 16);
                $table->json('reason');
                $table->json('cves')->nullable();
                $table->string('url', 500)->nullable();
                $table->string('upstream')->nullable();
                $table->timestamp('published_at');
                $table->timestamp('deadline_at')->nullable();
                // A platform administrator moved the deadline (wins over deadline_at)
                $table->timestamp('deadline_extended_to')->nullable();
                $table->string('deadline_extended_by')->nullable();
                // Listed in the current feed; a patch the feed dropped stays for the records
                $table->boolean('in_feed')->default(true);
                $table->timestamp('removed_at')->nullable();
                $table->timestamp('announced_at')->nullable();
                $table->timestamps();

                $table->index(['runtime', 'version', 'in_feed']);
                $table->index('digest');
            });
        }

        if (! Schema::hasTable('platform_advisories')) {
            Schema::create('platform_advisories', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('version', 64)->unique();
                $table->string('level', 16);
                $table->json('reason');
                $table->string('url', 500)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->boolean('in_feed')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('runtime_patch_notices')) {
            Schema::create('runtime_patch_notices', function (Blueprint $table) {
                $table->ulid('id')->primary();
                // patch:<id> | eol:<runtime>:<version>:<weeks> | feed:<digest> | migration:<id>
                $table->string('subject', 191);
                // user:<id>[@<tenant>] | mail:<user id> | channel:<id>
                $table->string('recipient', 191);
                $table->string('tenant_id')->nullable()->index();
                $table->timestamp('created_at')->nullable();

                $table->unique(['subject', 'recipient']);
            });
        }

        if (Schema::hasTable('executor_images') && ! Schema::hasColumn('executor_images', 'digest')) {
            Schema::table('executor_images', function (Blueprint $table) {
                $table->string('digest', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('executor_images', 'digest')) {
            Schema::table('executor_images', function (Blueprint $table) {
                $table->dropColumn('digest');
            });
        }
        Schema::dropIfExists('runtime_patch_notices');
        Schema::dropIfExists('platform_advisories');
        Schema::dropIfExists('runtime_patches');
        Schema::dropIfExists('runtime_patch_feeds');
    }
};
