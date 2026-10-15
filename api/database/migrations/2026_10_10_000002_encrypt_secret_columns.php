<?php

use App\Support\Secrets\SecretColumnEncryptor;
use App\Support\Secrets\SecretColumns;
use Illuminate\Database\Migrations\Migration;

/**
 * Encryption at rest (docs/security.md): JSON secret columns (env_vars, versions.config_snapshot,
 * knative_regions.registry_storage_config, log_retention_configs.storage_config) become text, and
 * every plaintext secret value already stored is encrypted with APP_KEY. Idempotent — encrypted
 * values are left as they are, so the migration can run again (or after `secrets:encrypt`).
 * Tenant schemas: Modules/Tenancy/Database/TenantMigrations/0041_encrypt_secret_columns.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $encryptor = new SecretColumnEncryptor($this->getConnection());

        $encryptor->widenColumns(SecretColumns::central());
        $encryptor->encrypt(SecretColumns::central());
    }

    /** Secrets are not written back as plaintext; the text columns keep working for JSON. */
    public function down(): void {}
};
