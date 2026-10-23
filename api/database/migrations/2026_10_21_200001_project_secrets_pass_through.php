<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pass-through project secrets (docs/security.md, "Secret environment variables"): the value of a
 * secret variable lives in the Kubernetes Secret of the project's region, RunR keeps metadata.
 *
 *  - project_secrets.value becomes nullable: null = the value is in the region (or the variable
 *    is a reference); a value written by an earlier release stays (encrypted) until
 *    `php artisan secrets:move-to-region` moves it;
 *  - source: region (a value RunR wrote into the region) | external (a reference to a secret
 *    store of the organization, synced by External Secrets Operator);
 *  - version: counts the writes of the value; fingerprint: keyed HMAC of the value
 *    (App\Services\Secrets\SecretFingerprint), never a plain hash;
 *  - secret_store_id, remote_path, remote_key: the reference; sync_status, sync_message,
 *    synced_at: what its ExternalSecret reported last;
 *  - secret_stores: the organization's stores (Vault / OpenBao, AWS Secrets Manager, GCP Secret
 *    Manager, Azure Key Vault) — settings only, they authenticate with the region's identity.
 *
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0078_project_secrets_pass_through.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_secrets')) {
            Schema::table('project_secrets', function (Blueprint $table) {
                $table->text('value')->nullable()->change();
            });

            $columns = [
                'source' => fn (Blueprint $t) => $t->string('source', 16)->default('region'),
                'version' => fn (Blueprint $t) => $t->unsignedInteger('version')->default(1),
                'fingerprint' => fn (Blueprint $t) => $t->string('fingerprint', 80)->nullable(),
                'secret_store_id' => fn (Blueprint $t) => $t->char('secret_store_id', 26)->nullable()->index(),
                'remote_path' => fn (Blueprint $t) => $t->string('remote_path', 512)->nullable(),
                'remote_key' => fn (Blueprint $t) => $t->string('remote_key')->nullable(),
                'sync_status' => fn (Blueprint $t) => $t->string('sync_status', 16)->nullable(),
                'sync_message' => fn (Blueprint $t) => $t->text('sync_message')->nullable(),
                'synced_at' => fn (Blueprint $t) => $t->timestamp('synced_at')->nullable(),
            ];
            foreach ($columns as $column => $add) {
                if (! Schema::hasColumn('project_secrets', $column)) {
                    Schema::table('project_secrets', fn (Blueprint $table) => $add($table));
                }
            }
        }

        if (! Schema::hasTable('secret_stores')) {
            Schema::create('secret_stores', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('name', 63)->unique();
                $table->string('provider', 16);          // vault | aws | gcp | azure
                $table->string('auth', 24);              // kubernetes | jwt | workload_identity
                $table->json('settings');                // addresses, roles, mounts — never a credential
                $table->unsignedInteger('refresh_interval')->nullable();
                $table->json('region_status')->nullable(); // region id => {status, message, checked_at}
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_stores');

        if (Schema::hasTable('project_secrets')) {
            foreach (['source', 'version', 'fingerprint', 'secret_store_id', 'remote_path', 'remote_key', 'sync_status', 'sync_message', 'synced_at'] as $column) {
                if (Schema::hasColumn('project_secrets', $column)) {
                    Schema::table('project_secrets', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
