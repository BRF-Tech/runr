<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The region's own OpenBao (docs/secret-stores.md, "OpenBao in the region"): RunR installs and
 * operates it as the third place a project secret can live (source "openbao"), and its database
 * secrets engine issues short-lived Postgres credentials to bindings.
 *
 *  - openbao_status / _message / _version / _installed_at: the install, like eso_*;
 *  - openbao_ca: the certificate of the CA that signed its TLS certificate — public, the
 *    SecretStores of the region trust it (the key pair lives in the region's Secret only);
 *  - openbao_default: new secret values of the region's projects go to OpenBao (true) or to the
 *    Kubernetes Secret <slug>-secret-env (false).
 *
 * The unseal key never reaches RunR's database: it lives in the region (Secret
 * runr-openbao-unseal, read by the unsealer next to OpenBao); the root token is revoked once the
 * install configured OpenBao.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('region_secret_settings')) {
            return;
        }

        $columns = [
            'openbao_status' => fn (Blueprint $t) => $t->string('openbao_status', 16)->default('not_installed'),
            'openbao_message' => fn (Blueprint $t) => $t->text('openbao_message')->nullable(),
            'openbao_version' => fn (Blueprint $t) => $t->string('openbao_version', 32)->nullable(),
            'openbao_installed_at' => fn (Blueprint $t) => $t->timestamp('openbao_installed_at')->nullable(),
            'openbao_ca' => fn (Blueprint $t) => $t->text('openbao_ca')->nullable(),
            'openbao_default' => fn (Blueprint $t) => $t->boolean('openbao_default')->default(true),
        ];
        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('region_secret_settings', $column)) {
                Schema::table('region_secret_settings', fn (Blueprint $table) => $add($table));
            }
        }
    }

    public function down(): void
    {
        foreach (['openbao_status', 'openbao_message', 'openbao_version', 'openbao_installed_at', 'openbao_ca', 'openbao_default'] as $column) {
            if (Schema::hasTable('region_secret_settings') && Schema::hasColumn('region_secret_settings', $column)) {
                Schema::table('region_secret_settings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
